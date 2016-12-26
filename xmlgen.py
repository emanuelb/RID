import re
from enum import Enum

class LineType(Enum):
    MINUS, PLUS, CONSTANT, SOURCE, ORDER, PROCESSOR, COMMENT = range(7)
    
class StripTypes(Enum):
    HEXDUMPBINARY, HEXDUMPUNKNOWN, NONE = range(3)

processors=(
    'Files',
    'Checksums-Sha256',
    'md5sums',
    'file list',
    'encoding',
    'metadata',
    'line order',

    'msgunfmt {}',
    'readelf --wide --dynamic {}',
    'readelf --wide --file-header {}',
    'readelf --wide --notes {}',
    'readelf --wide --program-header {}',
    'readelf --wide --relocs {}',
    'readelf --wide --sections {}',
    'readelf --wide --symbols {}',
    'readelf --wide --version-info {}',
    'readelf --string-dump=.gnu_debuglink {}',
    'readelf --wide --section-headers {}',
    
    'find {} -execdir llvm-dis -o - {} ;',
    'llvm-bcanalyzer -dump {}',

    'bzip2 --decompress --stdout {}',
    'cpio --quiet --numeric-uid-gid --force-local -tvF {}',
    'lsattr -d {}',    

    # gzip.py
    'gzip --decompress --stdout {}',

    # cbfs.py
    'cbfstool {} print',

    # ar.py
    'nm -s {}',
    
    # mono.py
    'pedump {}',

    # javascript.py
    'js-beautify {}',

    # openssh.py
    'ssh-keygen -l -f {}',

    # ppu.py
    'ppudump {}',    
    # java.py
    'javap -verbose -constants -s -l -private {}',
    
    # xz.py
    'xz --decompress --stdout {}',
    
    # unsquashfs
    'unsquashfs -s {}',
    'unsquashfs -d  -lls {}',

    # pdf.py
    'pdftotext {} -',
    'pdftk {} output - uncompress',
    # ps.py
    'ps2ascii {}',
    # iso9660.py
    'isoinfo -l -i {}',
    'isoinfo -l -i {} -R',
    'isoinfo -R -f -i {}',
    'isoinfo -d -i {}',
    'isoinfo -l -i {} -J -j iso8859-15',        
    # icc.py
    'cd-iccdump {}',
    # fonts.py
    'showttf {}',
    
    # sqlite.py
    'sqlite3 {} .dump',
    # png.py
    'sng',
    # zip.py
    'zipinfo {}',
    'zipinfo -v {}',
)

ProcessorsMatch = re.compile("^(objdump --line-numbers --disassemble --demangle --section=|readelf --wide --debug-dump=|readelf --wide --decompress --hex-dump=|readelf --wide --decompress --string-dump=|otool -arch )")

# when file-type & extension is unknown
HexDumpUnknownDetect = re.compile("^[0-9a-f]{8}: (?:[0-9a-f]{4} ){8} ")

# from readelf 
HexDumpBinaryDetect = re.compile('^  0x(?:[0-9a-f]{8} ){1,5}');