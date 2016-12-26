# Converting diffoscope txt output to xml format
import sys
import re
import xmlgen
import xml.etree.cElementTree as ET
    
def process( filecontent , file_path_input ):
    lines = filecontent.split("\n")
    lenlines = len(lines)
    filepath1 = lines[0][4:]
    filepath2 = lines[1][4:]

    #TODO: CHECK FOR: PYTHONIOENCODING=utf-8
    
    root = ET.Element("diffoscope", type='txt_to_xml')
    ET.SubElement(root, "file1").text = filepath1
    ET.SubElement(root, "file2").text = filepath2    
    diff = ET.SubElement(root,"diff")
    cur = diff
    
    # TBD -
    sys.exit("TXT parsing will be implemented soon, meanwhile use HTML output instead")
    
    tree = ET.ElementTree(root)
    tree.write(file_path_input+".txt.xml")