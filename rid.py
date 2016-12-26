import sys
import os
import txt_to_xml
import html_to_xml
import xml_parser

if len(sys.argv) < 2:
    sys.exit('Usage: %s path-to-diffoscope-html-or-txt-or-xml-output' % sys.argv[0])

file_path_input = sys.argv[1]
    
if not os.path.exists(file_path_input):
    sys.exit('ERROR: file: %s was not found!' % file_path_input)

MAX_FILE_SIZE = 25000000
file = open(file_path_input,"rt", encoding="utf-8")
filecontent = file.read(MAX_FILE_SIZE)
file.close()
filelength = len(filecontent)

print(file_path_input)

if filecontent[0:4] == "--- ":
    txt_to_xml.process(filecontent , file_path_input)
elif filecontent[0:4] == "<!DO":
    html_to_xml.process(filecontent , file_path_input)
elif filecontent[0:11] == "<diffoscope" or filecontent[0:6] == '<?xml ':
    xml_parser.process(filecontent , file_path_input)
elif filelength < 125: # Detection of 'diffoscope_runs_forever' issue
    error_pos = filecontent.find(" produced no output and was killed after running into timeout after ")
    if error_pos is not -1:
        ver_start = filecontent.rindex(" - diffoscope ")
        scan_date = filecontent[0:ver_start]
        scan_diffoscope_version = filecontent[ver_start+13:error_pos]
        scan_max_time = filecontent[error_pos+68:-3]
    else:
        sys.exit('ERROR: File %s not recognized as nither HTML or TXT or XML format' % sys.argv[1])
else:
    sys.exit('ERROR: File %s not recognized as nither HTML or TXT or XML format' % sys.argv[1])
