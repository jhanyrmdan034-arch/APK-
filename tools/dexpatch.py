#!/usr/bin/env python3
"""
dexpatch.py -- replace strings inside a DEX file, without Java.

The DEX files of this app use a *compact* map_list:

    map_off + 0   u32   number of map entries
    map_off + 4   entries, 12 bytes each:
                       u16 type
                       u16 unused
                       u32 size
                       u32 offset

(The usual Android layout has a 12 byte header in front of the entries;
this parser auto-detects both variants.)

How the strings are replaced
----------------------------
The string pool consists of

  * the *string_id table*  -> one u32 offset per string (it lives in the
    header area, before the data section, and never moves)
  * the *string_data items* -> uleb128 utf16-length + modified UTF-8 + 0x00

In this build the string_data items form one **contiguous** block that is
described by a single map_list entry (type 0x2002).  The Persian
translations are usually longer than the English originals, so the block
has to grow.  Growing it means that everything behind it inside the data
section moves -- and a number of tables store *absolute* offsets into
that area:

    proto_id_item.parameters_off            -> type_list_item
    class_def_item.static_values_off        -> encoded_array_item
    class_def_item.class_data_off           -> class_data_item
    class_def_item.annotations_off          -> annotation_set_item
    annotation_set_item.annotations_off     -> annotation_directory_item
    annotation_directory_item.*             -> annotation_*_item
    code_item.debug_info_off                -> debug_info_item

Therefore the patcher

  1. reads the old string_data block,
  2. rebuilds it with the translated strings,
  3. moves the whole tail of the file (everything behind the block) by
     the size difference,
  4. walks all the tables above and shifts every offset that points
     behind the old block,
  5. rewrites the map_list and recomputes the checksums.

Usage:
    python3 dexpatch.py <in.dex> <out.dex> <mapping.json>

mapping.json: { "classes3.dex": { "old text": "new text", ... }, ... }
"""

import hashlib
import json
import struct
import sys
import zlib

from dexsec import DexFile, decode_mutf8, encode_mutf8

TYPE_MAP_LIST = 0x1000
TYPE_STRING_DATA_ITEM = 0x2002


# ----------------------------------------------------------------------
# The map_list of this build carries *wrong* type codes: every entry is
# off by one (the offset points at the next type in the file).  The
# following table restores the real meaning -- it was derived from the
# content of the file, not from the type codes.
# ----------------------------------------------------------------------
REAL_MAP_TYPES = {
    0x0000: 0x0000,  # header_item
    0x0001: 0x0001,  # string_id_item
    0x0002: 0x0002,  # type_id_item
    0x0003: 0x0003,  # proto_id_item
    0x0004: 0x0004,  # field_id_item
    0x0005: 0x0005,  # method_id_item
    0x0006: 0x0006,  # class_def_item
    0x2002: 0x2002,  # string_data_item
    0x1001: 0x1001,  # type_list
    0x2004: 0x2004,  # annotation_item
    0x1003: 0x2003,  # annotation_set_item
    0x2006: 0x2006,  # annotations_directory_item
    0x2003: 0x2005,  # encoded_array_item
    0x2001: 0x2001,  # code_item
    0x2000: 0x2000,  # class_data_item
    0x1000: 0x1000,  # map_list
}

# the real type code of a map entry (the file uses a shifted code)
def _real_type(code):
    return REAL_MAP_TYPES.get(code, code)


def _uleb(value):
    out = bytearray()
    while True:
        b = value & 0x7F
        value >>= 7
        if value:
            out.append(b | 0x80)
        else:
            out.append(b)
            break
    return bytes(out)


def _read_map_list(data: bytes, map_off: int):
    """returns (entries, header_size) -- entries are [type, unused, size, offset]"""
    if not map_off:
        return [], 0

    first = struct.unpack_from("<I", data, map_off)[0]

    # compact variant: u32 count, then 12 byte entries
    if 0 < first <= 4096:
        entries = []
        ok = True
        for i in range(first):
            p = map_off + 4 + 12 * i
            if p + 12 > len(data):
                ok = False
                break
            t, u, s, o = struct.unpack_from("<HHII", data, p)
            entries.append([t, u, s, o])
        if ok and entries:
            return entries, 4

    # standard android variant
    t, u, size, unused, count = struct.unpack_from("<HHIII", data, map_off)
    if t == TYPE_MAP_LIST and count <= 4096:
        entries = []
        for i in range(count):
            p = map_off + 16 + 12 * i
            it, iu, isz, ioff = struct.unpack_from("<HHII", data, p)
            entries.append([it, iu, isz, ioff])
        return entries, 16

    return [], 0


def _write_map_list(entries, header_size: int) -> bytes:
    out = bytearray()
    if header_size == 4:
        out += struct.pack("<I", len(entries))
    else:
        out += struct.pack("<HHIII", TYPE_MAP_LIST, 0, 0, 0, len(entries))
    for e in entries:
        out += struct.pack("<HHII", e[0], e[1], e[2], e[3])
    return bytes(out)


def _read_string_data(data: bytes, off: int):
    """reads a string_data item at off -> (utf16_size, raw_bytes, item_length)"""
    i = off
    shift = 0
    utf16_size = 0
    while True:
        b = data[i]
        i += 1
        utf16_size |= (b & 0x7F) << shift
        shift += 7
        if not (b & 0x80):
            break
    end = data.index(b"\x00", i)
    return utf16_size, data[i:end], end - off + 1


# ----------------------------------------------------------------------
# walking helpers for the tables that hold absolute offsets
# ----------------------------------------------------------------------
def _uleb_len(data, off):
    i = off
    shift = 0
    value = 0
    while True:
        b = data[i]
        i += 1
        value |= (b & 0x7F) << shift
        shift += 7
        if not (b & 0x80):
            break
    return value, i


def _sleb_len(data, off):
    """reads a signed uleb128 -> (value, next_offset)"""
    i = off
    shift = 0
    value = 0
    while True:
        b = data[i]
        i += 1
        value |= (b & 0x7F) << shift
        shift += 7
        if not (b & 0x80):
            break
    if b & 0x40:
        value -= 1 << shift
    return value, i


def _skip_encoded_value(data, off):
    """skips one encoded_value -> returns the offset after it"""
    b = data[off]
    off += 1
    value_type = b & 0x1F
    value_arg = (b >> 5) & 0x07
    if value_type == 0x1E:  # array
        size, off = _uleb_len(data, off)
        for _ in range(size):
            off = _skip_encoded_value(data, off)
        return off
    if value_type == 0x1F:  # annotation
        off, _ = _uleb_len(data, off)
        off, _ = _uleb_len(data, off)
        off, _ = _uleb_len(data, off)
        off, _ = _uleb_len(data, off)
        return off
    if value_type == 0x00:  # byte
        return off + 1
    if value_type == 0x02:  # short
        return off + 2
    if value_type == 0x04:  # int
        return off + 4
    if value_type == 0x06:  # long
        return off + 8
    if value_type == 0x10:  # float
        return off + 4
    if value_type == 0x11:  # double
        return off + 8
    if value_type == 0x15:  # method_type
        return off + 2
    if value_type == 0x16:  # method_handle
        return off + 2
    if value_type == 0x17:  # string
        return off + 2
    if value_type == 0x18:  # type
        return off + 2
    if value_type == 0x19:  # field
        return off + 2
    if value_type == 0x1A:  # method
        return off + 2
    if value_type == 0x1B:  # enum
        return off + 2
    # everything else: value_arg+1 bytes
    return off + value_arg + 1


def _encoded_array_offsets(data, off):
    """yields the offsets of every encoded_value inside an encoded_array_item"""
    size, cur = _uleb_len(data, off)
    for _ in range(size):
        yield cur
        cur = _skip_encoded_value(data, cur)


def _shift_uleb_value(value, delta):
    """returns value + delta (the caller re-encodes it)"""
    return value + delta


def _catch_handler_list_size(data, off):
    """returns the total byte size of the encoded_catch_handler_list at off"""
    start = off
    list_size, cur = _uleb_len(data, off)
    for _ in range(list_size):
        size, cur = _sleb_len(data, cur)
        for _ in range(abs(size)):
            _, cur = _uleb_len(data, cur)   # type_idx
            _, cur = _uleb_len(data, cur)   # addr
        if size <= 0:
            _, cur = _uleb_len(data, cur)   # catch_all_addr
    # the catch handler list is 4 byte aligned
    while (cur - start) % 4:
        cur += 1
    return cur - start


def _read_class_data(data, off):
    """parses a class_data_item -> (size, new_offset, code_off_positions)

    code_off is a uleb128 in this build, so its length can change when the
    offset is shifted; the caller therefore needs the exact positions.
    """
    cur = off
    static_fields_size, cur = _uleb_len(data, cur)
    instance_fields_size, cur = _uleb_len(data, cur)
    direct_methods_size, cur = _uleb_len(data, cur)
    virtual_methods_size, cur = _uleb_len(data, cur)

    for count in (static_fields_size, instance_fields_size):
        for _ in range(count):
            _, cur = _uleb_len(data, cur)
            _, cur = _uleb_len(data, cur)
            if cur > len(data):
                return None, cur, []

    code_positions = []
    for count in (direct_methods_size, virtual_methods_size):
        for _ in range(count):
            _, cur = _uleb_len(data, cur)
            _, cur = _uleb_len(data, cur)
            if cur >= len(data):
                return None, cur, code_positions
            code_positions.append(cur)
            _, cur = _uleb_len(data, cur)

    return (static_fields_size, instance_fields_size,
            direct_methods_size, virtual_methods_size), cur, code_positions


def _rewrite_class_data(data, off, end, code_positions):
    """rebuilds a class_data_item with the shifted code_off values"""
    out = bytearray()
    cur = off
    for _ in range(4):
        v, cur = _uleb_len(data, cur)
        out += _uleb(v)
    sf, inf, dm, vm = _class_data_sizes(data, off)
    for count in (sf, inf):
        for _ in range(count):
            v, cur = _uleb_len(data, cur)
            out += _uleb(v)
            v, cur = _uleb_len(data, cur)
            out += _uleb(v)
    for count in (dm, vm):
        for _ in range(count):
            v, cur = _uleb_len(data, cur)
            out += _uleb(v)
            v, cur = _uleb_len(data, cur)
            out += _uleb(v)
            # code_off
            old, cur = _uleb_len(data, cur)
            out += _uleb(old)
    return bytes(out)


def _class_data_sizes(data, off):
    cur = off
    sf, cur = _uleb_len(data, cur)
    inf, cur = _uleb_len(data, cur)
    dm, cur = _uleb_len(data, cur)
    vm, cur = _uleb_len(data, cur)
    return sf, inf, dm, vm


def _annotation_set_offsets(data, off, count):
    """yields the u32 annotations_off of a run of annotation_set_items

    The annotation_set items of this build are stored back to back:
    each one is  u32 size  followed by  size * u32 annotations_off.
    The annotations_off values point at annotation_item entries which
    live in front of the sets, so they have to be shifted as well.
    """
    cur = off
    for _ in range(count):
        size, = struct.unpack_from("<I", data, cur)
        cur += 4
        for _ in range(size):
            yield cur  # the annotations_off (points at an annotation_item)
            cur += 4


def _annotation_directory_offsets(data, off, end):
    """yields the u32 offset fields of an annotations_directory_item"""
    _, fields_size, methods_size, params_size = struct.unpack_from("<4I", data, off)
    yield off  # class_annotations_off -> an annotation_set
    cur = off + 16
    for _ in range(fields_size):
        if cur + 8 > end:
            return
        yield cur + 4  # field_annotations_off
        cur += 8
    for _ in range(methods_size):
        if cur + 8 > end:
            return
        yield cur + 4  # method_annotations_off
        cur += 8
    for _ in range(params_size):
        if cur + 8 > end:
            return
        yield cur + 4  # parameter_annotations_off
        cur += 8


def _next_annotation_directory(data, off):
    """returns the offset of the next annotations_directory_item, or None"""
    _, fields_size, methods_size, params_size = struct.unpack_from("<4I", data, off)
    cur = off + 16
    cur += 8 * (fields_size + methods_size + params_size)
    if cur > len(data):
        return None
    return cur


def _annotation_item_offsets(data, off, count):
    """yields the u32 offsets inside a run of annotation_item entries

    annotation_item = u8 visibility + encoded_annotation
    encoded_annotation = uleb128 type_idx + uleb128 size
                        + uleb128 name_idx + size * encoded_value
    The encoded values contain no absolute offsets, so nothing inside
    an annotation item has to be shifted.
    """
    return


def patch_dex(data: bytes, replacements: dict):
    """returns (new_dex_bytes, applied_list)

    The DEX of this app deviates from the Android specification in three
    places, all of which are handled here:

      * the map_list uses a compact header (u32 count + 12 byte entries)
        and its type codes are shifted by one entry,
      * the string_data items are addressed through the string_id table,
      * the code_off of an encoded_method is a uleb128 instead of a u32.

    Because the Persian translations are longer than the English
    originals, the data section grows.  Every offset that points into
    the data section is therefore shifted, and the class_data items are
    rebuilt (their code_off may need one more byte).
    """
    dex = DexFile(data)
    H = dex.header

    todo = {}
    for s in dex.strings:
        if s in replacements and replacements[s] != s and s not in todo:
            todo[s] = replacements[s]

    if not todo:
        return data, []

    data_off = H["data_off"]
    map_off = H.get("map_off", 0)
    entries, map_header_size = _read_map_list(data, map_off)
    if not entries:
        raise RuntimeError("no map_list found")

    # The map_list of this build carries type codes that are shifted by
    # one entry.  The *real* type is needed to find the groups, but the
    # original codes have to be written back -- the runtime (and every
    # DEX parser) expects them.
    real = [[_real_type(e[0]), e[1], e[2], e[3]] for e in entries]

    sd_entry = None
    for e in real:
        if e[0] == TYPE_STRING_DATA_ITEM:
            sd_entry = e
            break
    if sd_entry is None:
        raise RuntimeError("no string_data_item entry in the map_list")

    # ------------------------------------------------------------------
    # 1) the string_data block
    # ------------------------------------------------------------------
    string_ids_off = H["string_ids_off"]
    id_offsets = [
        struct.unpack_from("<I", data, string_ids_off + 4 * i)[0]
        for i in range(H["string_ids_size"])
    ]
    block_start = min(id_offsets)
    block_end = block_start
    for off in id_offsets:
        _, _, length = _read_string_data(data, off)
        block_end = max(block_end, off + length)

    old_items = []
    for off in id_offsets:
        utf16_size, raw, _ = _read_string_data(data, off)
        old_items.append((utf16_size, raw))

    new_items = []
    applied = []
    for utf16_size, raw in old_items:
        text = decode_mutf8(raw)
        if text in todo:
            new_text = todo[text]
            applied.append((text, new_text))
            new_items.append((len(new_text), encode_mutf8(new_text)))
        else:
            new_items.append((utf16_size, raw))

    new_block = bytearray()
    new_offsets = []
    for utf16_size, blob in new_items:
        new_offsets.append(block_start + len(new_block))
        new_block += _uleb(utf16_size) + blob + b"\x00"
    while len(new_block) % 4:
        new_block.append(0)

    # ------------------------------------------------------------------
    # 2) rebuild the class_data items (code_off is a uleb128 here)
    # ------------------------------------------------------------------
    class_def_off = H["class_defs_off"]
    cd_offsets = []
    for i in range(H["class_defs_size"]):
        p = class_def_off + 32 * i + 24
        cd_off, = struct.unpack_from("<I", data, p)
        cd_offsets.append(cd_off if cd_off else None)

    # every class_data item, in file order, with its code_off values
    cd_items = []
    for cd_off in cd_offsets:
        if not cd_off:
            continue
        sizes, end, positions = _read_class_data(data, cd_off)
        if sizes is None:
            continue
        cd_items.append((cd_off, end, positions))

    # ------------------------------------------------------------------
    # 3) lay out the new data section
    #
    #    The data section is rebuilt from scratch in the original order:
    #    string_data block, then everything that followed it.  The
    #    class_data items are re-emitted with their (possibly longer)
    #    code_off values.
    # ------------------------------------------------------------------
    tail = data[block_end:]

    # where does each class_data item live inside the tail?
    cd_positions = {}
    for cd_off, end, positions in cd_items:
        cd_positions[cd_off] = (cd_off - block_end, end - block_end)

    # rebuild the class_data items with the shifted code_off
    def _shift_cd(cd_off, end, positions):
        """returns the new bytes of one class_data item"""
        cur = cd_off
        out = bytearray()
        for _ in range(4):
            v, cur = _uleb_len(data, cur)
            out += _uleb(v)
        sf, inf, dm, vm = _class_data_sizes(data, cd_off)
        for count in (sf, inf):
            for _ in range(count):
                v, cur = _uleb_len(data, cur)
                out += _uleb(v)
                v, cur = _uleb_len(data, cur)
                out += _uleb(v)
        for count in (dm, vm):
            for _ in range(count):
                v, cur = _uleb_len(data, cur)
                out += _uleb(v)
                v, cur = _uleb_len(data, cur)
                out += _uleb(v)
                old, cur = _uleb_len(data, cur)
                out += _uleb(old)
        return bytes(out)

    # the class_data items are stored back to back at the end of the
    # data section -- rewrite them in place
    tail = bytearray(tail)
    for cd_off, end, positions in sorted(cd_items, key=lambda x: x[0]):
        start = cd_off - block_end
        stop = end - block_end
        tail[start:stop] = _shift_cd(cd_off, end, positions)

    # the code_off values inside the class_data items have to be shifted
    # by the size difference of the string_data block -- but that size
    # difference is only known after the class_data items were rebuilt
    # (the code_off may have grown).  We therefore compute it
    # iteratively.
    string_delta = len(new_block) - (block_end - block_start)
    for _ in range(4):
        cd_delta = 0
        for cd_off, end, positions in cd_items:
            new_len = len(_shift_cd(cd_off, end, positions))
            cd_delta += new_len - (end - cd_off)
        total = string_delta + cd_delta
        # rebuild the class_data items with the shifted code_off
        def _shift_cd2(cd_off, end, positions, _t=total):
            cur = cd_off
            out = bytearray()
            for _ in range(4):
                v, cur = _uleb_len(data, cur)
                out += _uleb(v)
            sf, inf, dm, vm = _class_data_sizes(data, cd_off)
            for count in (sf, inf):
                for _ in range(count):
                    v, cur = _uleb_len(data, cur)
                    out += _uleb(v)
                    v, cur = _uleb_len(data, cur)
                    out += _uleb(v)
            for count in (dm, vm):
                for _ in range(count):
                    v, cur = _uleb_len(data, cur)
                    out += _uleb(v)
                    v, cur = _uleb_len(data, cur)
                    out += _uleb(v)
                    old, cur = _uleb_len(data, cur)
                    out += _uleb(_shift_uleb_value(old, _t))
            return bytes(out)

        new_tail = bytearray(tail)
        for cd_off, end, positions in sorted(cd_items, key=lambda x: x[0]):
            start = cd_off - block_end
            stop = end - block_end
            new_tail[start:stop] = _shift_cd2(cd_off, end, positions)
        if len(new_tail) == len(tail) and new_tail == tail:
            tail = new_tail
            break
        tail = new_tail

    out = bytearray(data[:block_start]) + new_block + tail
    delta = len(out) - len(data)

    # ------------------------------------------------------------------
    # 4) shift every offset that points into the data section
    # ------------------------------------------------------------------
    def shift(off):
        return off + delta if off >= block_end else off

    for e in real:
        if e[0] != TYPE_MAP_LIST:
            e[3] = shift(e[3])

    for i, off in enumerate(new_offsets):
        struct.pack_into("<I", out, string_ids_off + 4 * i, off)

    # proto_id_item.parameters_off
    proto_off = H["proto_ids_off"]
    for i in range(H["proto_ids_size"]):
        p = proto_off + 12 * i + 8
        v, = struct.unpack_from("<I", out, p)
        struct.pack_into("<I", out, p, shift(v))

    # class_def_item
    #   annotations_off  -> an *index* into the annotation_set group
    #                       (it must not be shifted)
    #   class_data_off   -> a file offset
    #   static_values_off-> a file offset
    #   In this build the class_def_item has a different layout than in
    #   the specification:
    #       u4 class_idx
    #       u4 access_flags
    #       u4 superclass_idx
    #       u4 interfaces_off
    #       u4 annotations_off   (an *index* into the annotation_set group)
    #       u4 static_values_off  (a file offset)
    #       u4 class_data_off     (a file offset)
    #       u4 reserved
    for i in range(H["class_defs_size"]):
        p = class_def_off + 32 * i
        # class_def_item: class_idx(u4) access(u2) reserved(u2)
        #                superclass_idx(u4) interfaces_off(u4)
        #                source_file_idx(u4) annotations_off(u4)
        #                class_data_off(u4) static_values_off(u4)
        #   interfaces_off  -> a type_list (file offset)
        #   annotations_off -> an *index* into the annotation_set group
        for rel in (12, 20, 24):  # interfaces_off, class_data_off, static_values_off
            v, = struct.unpack_from("<I", out, p + rel)
            struct.pack_into("<I", out, p + rel, shift(v))

    # code_item.debug_info_off
    for e in real:
        if e[0] != 0x2001:
            continue
        off = e[3]
        for _ in range(e[2]):
            if off + 16 > len(out):
                break
            regs, ins, outs, tries = struct.unpack_from("<HHHH", out, off)
            dbg, insns = struct.unpack_from("<II", out, off + 8)
            struct.pack_into("<I", out, off + 8, shift(dbg))
            size = 16 + insns * 2
            if tries:
                if insns % 2:
                    size += 2
                size += tries * 8
                size += _catch_handler_list_size(out, off + size)
            off += size
            while off % 4:
                off += 1
        break

    # annotation_set_item -> annotations_off (points at annotation_item)
    #
    #     The annotation sets are stored back to back; every one of them
    #     holds a number of u32 offsets into the annotation_item area.
    #     Those offsets are absolute and have to be shifted.
    for e in real:
        if e[0] != 0x2003:  # annotation_set_item
            continue
        for rel in _annotation_set_offsets(out, e[3], e[2]):
            v, = struct.unpack_from("<I", out, rel)
            struct.pack_into("<I", out, rel, shift(v))
        break

    # annotations_directory_item -> class_annotations_off (points at an
    # annotation_set) -- and the field/method/parameter annotations.
    for e in real:
        if e[0] != 0x2006:  # annotations_directory_item
            continue
        off = e[3]
        for _ in range(e[2]):
            nxt = _next_annotation_directory(out, off)
            if nxt is None:
                break
            for rel in _annotation_directory_offsets(out, off, nxt):
                v, = struct.unpack_from("<I", out, rel)
                struct.pack_into("<I", out, rel, shift(v))
            off = nxt
        break

    # the map entry of the string_data block
    for e in real:
        if e[0] == TYPE_STRING_DATA_ITEM:
            e[2] = len(new_offsets)
            e[3] = block_start
            break
    # write the (possibly corrected) values back into the original entries
    # (the type codes stay exactly as they were)
    for orig, real_e in zip(entries, real):
        orig[2] = real_e[2]
        orig[3] = real_e[3]

    # ------------------------------------------------------------------
    # 5) rewrite the map_list
    # ------------------------------------------------------------------
    if map_header_size == 4:
        old_map_len = 4 + 12 * len(entries)
    else:
        old_map_len = 16 + 12 * len(entries)

    map_start = len(out) - old_map_len
    if map_start < 0:
        raise RuntimeError("map_list not at the end of the file")

    for e in real:
        if e[0] == TYPE_MAP_LIST:
            e[2] = 1
            e[3] = map_start
            break
    for orig, real_e in zip(entries, real):
        orig[2] = real_e[2]
        orig[3] = real_e[3]

    out[map_start:] = _write_map_list(entries, map_header_size)
    struct.pack_into("<I", out, 0x34, map_start)

    # ------------------------------------------------------------------
    # 6) header + checksums
    # ------------------------------------------------------------------
    struct.pack_into("<I", out, 32, len(out))
    struct.pack_into("<I", out, 0x68, len(out) - data_off)
    struct.pack_into("<I", out, 0x6C, data_off)

    out[12:32] = hashlib.sha1(bytes(out[32:])).digest()
    struct.pack_into("<I", out, 8, zlib.adler32(bytes(out[12:])) & 0xFFFFFFFF)

    return bytes(out), applied


def main():
    if len(sys.argv) != 4:
        print(__doc__)
        sys.exit(1)
    inp, outp, mapping_file = sys.argv[1], sys.argv[2], sys.argv[3]

    with open(mapping_file, "r", encoding="utf-8") as fh:
        mapping = json.load(fh)

    base = inp.split("/")[-1]
    repl = mapping.get(base, {})
    if not repl:
        print(f"no mapping for {base}")
        sys.exit(2)

    data = open(inp, "rb").read()
    new_data, applied = patch_dex(data, repl)
    open(outp, "wb").write(new_data)
    print(f"[{base}] patched {len(applied)} strings")
    for old, new in applied:
        print(f"   {old!r} -> {new!r}")


if __name__ == "__main__":
    main()
