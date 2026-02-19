/**
 * exif.js - 轻量级媒体元数据解析器
 * 支持 JPEG EXIF（拍摄时间 + GPS）和 MP4/MOV（创建时间 + GPS）
 * 如果解析失败，使用 File.lastModified 兜底
 */

const VIDEO_EXTS = ['mp4', 'mov', 'webm', 'avi', '3gp', 'm4v'];

/**
 * 从 File 对象提取元数据（EXIF/MP4 优先，File.lastModified 兜底）
 * @param {File} file
 * @returns {Promise<{date?: string, latitude?: number, longitude?: number} | null>}
 */
export async function extractMetadata(file) {
    // 判断是否为视频文件
    const ext = (file.name || '').split('.').pop().toLowerCase();
    const isVideoFile = VIDEO_EXTS.includes(ext) || (file.type && file.type.startsWith('video/'));

    const meta = isVideoFile ? await readVideoMeta(file) : await readExif(file);

    const result = {
        date: meta?.date || null,
        latitude: meta?.latitude ?? null,
        longitude: meta?.longitude ?? null
    };

    // 日期兜底：使用 File.lastModified
    if (!result.date && file && file.lastModified) {
        const d = new Date(file.lastModified);
        if (!isNaN(d.getTime())) {
            result.date = formatDateStr(d);
        }
    }

    return (result.date || result.latitude != null) ? result : null;
}

/**
 * 格式化 Date 对象为 EXIF 格式字符串 "YYYY:MM:DD HH:mm:ss"
 */
function formatDateStr(d) {
    return [
        d.getFullYear(),
        String(d.getMonth() + 1).padStart(2, '0'),
        String(d.getDate()).padStart(2, '0')
    ].join(':') + ' ' + [
        String(d.getHours()).padStart(2, '0'),
        String(d.getMinutes()).padStart(2, '0'),
        String(d.getSeconds()).padStart(2, '0')
    ].join(':');
}

// ==================== MP4/MOV 元数据解析 ====================

/**
 * 从 MP4/MOV 文件中提取创建时间和 GPS 坐标
 * 通过解析 moov 原子中的 mvhd（时间）和 udta/©xyz（GPS）
 */
async function readVideoMeta(file) {
    try {
        const moovBuf = await findMoovAtom(file);
        if (!moovBuf) return null;

        const view = new DataView(moovBuf);
        const result = {};

        // 解析 mvhd 获取创建时间
        const mvhd = findAtomIn(view, 0, moovBuf.byteLength, 0x6D766864); // 'mvhd'
        if (mvhd) {
            const version = view.getUint8(mvhd.start);
            let creationTime;
            if (version === 0) {
                creationTime = view.getUint32(mvhd.start + 4);
            } else {
                // version 1: 64-bit timestamp
                const high = view.getUint32(mvhd.start + 4);
                const low = view.getUint32(mvhd.start + 8);
                creationTime = high * 0x100000000 + low;
            }
            // Mac epoch (1904-01-01) 转 Unix epoch，差值 2082844800 秒
            if (creationTime > 2082844800) {
                const d = new Date((creationTime - 2082844800) * 1000);
                if (!isNaN(d.getTime()) && d.getFullYear() >= 2000) {
                    result.date = formatDateStr(d);
                }
            }
        }

        // 解析 udta/©xyz 获取 GPS 坐标
        const udta = findAtomIn(view, 0, moovBuf.byteLength, 0x75647461); // 'udta'
        if (udta) {
            // ©xyz = 0xA978797A
            const xyz = findAtomIn(view, udta.start, udta.end, 0xA978797A);
            if (xyz) {
                // 跳过 2 字节字符串长度 + 2 字节语言代码
                const strStart = xyz.start + 4;
                const strLen = xyz.end - strStart;
                if (strLen > 0 && strLen < 200) {
                    const bytes = new Uint8Array(moovBuf, strStart, strLen);
                    const str = new TextDecoder().decode(bytes);
                    const gps = parseISO6709(str);
                    if (gps) {
                        result.latitude = gps.latitude;
                        result.longitude = gps.longitude;
                    }
                }
            }
        }

        return (result.date || result.latitude != null) ? result : null;
    } catch (e) {
        console.warn('[MP4] 解析失败:', e);
        return null;
    }
}

/**
 * 扫描文件顶层原子，找到 moov 并读取其内容
 * moov 可能在文件头部（fast start）或尾部（mdat 之后）
 */
async function findMoovAtom(file) {
    let offset = 0;
    const fileSize = file.size;
    // 最多扫描 20 个顶层原子，防止异常文件死循环
    for (let i = 0; i < 20 && offset + 8 <= fileSize; i++) {
        const hdrBuf = await file.slice(offset, offset + 8).arrayBuffer();
        const hv = new DataView(hdrBuf);
        let size = hv.getUint32(0);
        const type = hv.getUint32(4);

        // 扩展尺寸（size == 1 表示后 8 字节为 64 位大小）
        let headerLen = 8;
        if (size === 1 && offset + 16 <= fileSize) {
            const extBuf = await file.slice(offset + 8, offset + 16).arrayBuffer();
            const ev = new DataView(extBuf);
            size = ev.getUint32(0) * 0x100000000 + ev.getUint32(4);
            headerLen = 16;
        }
        // size == 0 表示原子延伸到文件末尾
        if (size === 0) size = fileSize - offset;
        if (size < 8) break;

        // 'moov' = 0x6D6F6F76
        if (type === 0x6D6F6F76) {
            const contentSize = Math.min(size - headerLen, 5 * 1024 * 1024); // 最多读 5MB
            return await file.slice(offset + headerLen, offset + headerLen + contentSize).arrayBuffer();
        }

        offset += size;
    }
    return null;
}

/**
 * 在 buffer 中查找指定类型的子原子
 */
function findAtomIn(view, start, end, typeCode) {
    let offset = start;
    while (offset + 8 <= end) {
        const size = view.getUint32(offset);
        const type = view.getUint32(offset + 4);
        if (size < 8) break;
        if (type === typeCode) {
            return { start: offset + 8, end: Math.min(offset + size, end) };
        }
        offset += size;
    }
    return null;
}

/**
 * 解析 ISO 6709 格式的 GPS 字符串
 * 格式: "+34.0522-118.2437+026.774/" 或 "+34.0522-118.2437/"
 */
function parseISO6709(str) {
    const match = str.match(/([+-][\d.]+)([+-][\d.]+)/);
    if (match) {
        const lat = parseFloat(match[1]);
        const lng = parseFloat(match[2]);
        if (!isNaN(lat) && !isNaN(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180) {
            return { latitude: lat, longitude: lng };
        }
    }
    return null;
}

/**
 * 从 File/Blob 对象中读取 JPEG EXIF 信息
 */
async function readExif(file) {
    try {
        // 只读前 128KB，EXIF 数据一般在文件头部
        const slice = file.slice(0, 128 * 1024);
        const buffer = await readAsArrayBuffer(slice);
        const view = new DataView(buffer);

        // 检查 JPEG 魔数 0xFFD8
        if (view.byteLength < 4 || view.getUint16(0) !== 0xFFD8) return null;

        // 查找 APP1 (EXIF) 标记 0xFFE1
        let offset = 2;
        while (offset + 4 < view.byteLength) {
            const marker = view.getUint16(offset);
            if (marker === 0xFFE1) {
                const segLen = view.getUint16(offset + 2);
                return parseExifSegment(view, offset + 4, segLen - 2);
            }
            // 非 JPEG 标记，退出
            if ((marker & 0xFF00) !== 0xFF00) break;
            offset += 2 + view.getUint16(offset + 2);
        }

        return null;
    } catch (e) {
        console.warn('[EXIF] 解析失败:', e);
        return null;
    }
}

function readAsArrayBuffer(blob) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.onerror = reject;
        reader.readAsArrayBuffer(blob);
    });
}

/**
 * 解析 EXIF APP1 段
 */
function parseExifSegment(view, offset, length) {
    // 校验 "Exif\0\0" 头
    if (offset + 6 > view.byteLength) return null;
    const h0 = view.getUint8(offset);
    const h1 = view.getUint8(offset + 1);
    const h2 = view.getUint8(offset + 2);
    const h3 = view.getUint8(offset + 3);
    if (h0 !== 0x45 || h1 !== 0x78 || h2 !== 0x69 || h3 !== 0x66) return null; // "Exif"

    const tiffStart = offset + 6;
    if (tiffStart + 8 > view.byteLength) return null;

    // 字节序: 0x4949 = 小端 (II), 0x4D4D = 大端 (MM)
    const le = view.getUint16(tiffStart) === 0x4949;

    // 验证 TIFF 魔数 42
    if (view.getUint16(tiffStart + 2, le) !== 0x002A) return null;

    // IFD0 偏移
    const ifd0Offset = view.getUint32(tiffStart + 4, le);

    const result = {};

    // 解析 IFD0
    const ifd0 = parseIFD(view, tiffStart, ifd0Offset, le);

    // DateTime (IFD0, tag 0x0132) - 备选
    if (ifd0[0x0132]) {
        result.date = readTagString(view, tiffStart, ifd0[0x0132], le);
    }

    // ExifIFD 子目录 (tag 0x8769)
    if (ifd0[0x8769]) {
        const exifOffset = readTagLong(view, tiffStart, ifd0[0x8769], le);
        if (exifOffset != null) {
            const exifIfd = parseIFD(view, tiffStart, exifOffset, le);

            // DateTimeOriginal (优先级最高, tag 0x9003)
            if (exifIfd[0x9003]) {
                result.date = readTagString(view, tiffStart, exifIfd[0x9003], le);
            }
            // DateTimeDigitized (次优先, tag 0x9004)
            else if (exifIfd[0x9004]) {
                result.date = readTagString(view, tiffStart, exifIfd[0x9004], le);
            }
        }
    }

    // GPS IFD 子目录 (tag 0x8825)
    if (ifd0[0x8825]) {
        const gpsOffset = readTagLong(view, tiffStart, ifd0[0x8825], le);
        if (gpsOffset != null) {
            const gpsIfd = parseIFD(view, tiffStart, gpsOffset, le);

            // 纬度: GPSLatitudeRef (0x0001) + GPSLatitude (0x0002)
            if (gpsIfd[0x0001] && gpsIfd[0x0002]) {
                const ref = readTagString(view, tiffStart, gpsIfd[0x0001], le);
                const dms = readTagRationals(view, tiffStart, gpsIfd[0x0002], le, 3);
                if (ref && dms) {
                    result.latitude = dmsToDecimal(dms, ref);
                }
            }

            // 经度: GPSLongitudeRef (0x0003) + GPSLongitude (0x0004)
            if (gpsIfd[0x0003] && gpsIfd[0x0004]) {
                const ref = readTagString(view, tiffStart, gpsIfd[0x0003], le);
                const dms = readTagRationals(view, tiffStart, gpsIfd[0x0004], le, 3);
                if (ref && dms) {
                    result.longitude = dmsToDecimal(dms, ref);
                }
            }
        }
    }

    return (result.date || result.latitude != null) ? result : null;
}

/**
 * 解析 IFD (Image File Directory)
 * 返回 { tagId: { type, count, valueOffset } }
 */
function parseIFD(view, tiffStart, ifdOffset, le) {
    const abs = tiffStart + ifdOffset;
    const entries = {};

    if (abs + 2 > view.byteLength) return entries;
    const count = view.getUint16(abs, le);

    for (let i = 0; i < count; i++) {
        const pos = abs + 2 + i * 12;
        if (pos + 12 > view.byteLength) break;

        const tag = view.getUint16(pos, le);
        const type = view.getUint16(pos + 2, le);
        const cnt = view.getUint32(pos + 4, le);
        entries[tag] = { type, count: cnt, valueOffset: pos + 8 };
    }

    return entries;
}

// 各 EXIF 类型的单值字节长度
const TYPE_SIZE = { 1: 1, 2: 1, 3: 2, 4: 4, 5: 8, 7: 1, 9: 4, 10: 8 };

/**
 * 获取 tag 值的实际起始地址
 * 数据 ≤ 4 字节时直接内联在 valueOffset 位置，否则 valueOffset 处存的是偏移量
 */
function getDataOffset(view, tiffStart, entry, le) {
    const totalBytes = (TYPE_SIZE[entry.type] || 1) * entry.count;
    if (totalBytes <= 4) {
        return entry.valueOffset;
    }
    const off = view.getUint32(entry.valueOffset, le);
    return tiffStart + off;
}

function readTagString(view, tiffStart, entry, le) {
    const off = getDataOffset(view, tiffStart, entry, le);
    let s = '';
    for (let i = 0; i < entry.count; i++) {
        if (off + i >= view.byteLength) break;
        const ch = view.getUint8(off + i);
        if (ch === 0) break;
        s += String.fromCharCode(ch);
    }
    return s || null;
}

function readTagLong(view, tiffStart, entry, le) {
    if (entry.count !== 1) return null;
    return view.getUint32(entry.valueOffset, le);
}

function readTagRationals(view, tiffStart, entry, le, n) {
    if (entry.type !== 5 || entry.count < n) return null;
    const off = getDataOffset(view, tiffStart, entry, le);
    const values = [];
    for (let i = 0; i < n; i++) {
        const pos = off + i * 8;
        if (pos + 8 > view.byteLength) return null;
        const num = view.getUint32(pos, le);
        const den = view.getUint32(pos + 4, le);
        values.push(den === 0 ? 0 : num / den);
    }
    return values;
}

/**
 * DMS (度/分/秒) 转十进制度
 */
function dmsToDecimal(dms, ref) {
    const dec = dms[0] + dms[1] / 60 + dms[2] / 3600;
    return (ref === 'S' || ref === 'W') ? -dec : dec;
}
