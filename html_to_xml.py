# Converting diffoscope html output to xml format
import os
import re
import sys
import xmlgen
import pathlib
import xml.etree.cElementTree as ET
from bs4 import BeautifulSoup

# For Order Detection

# for 'file list'
def remove_prems(list):
    # -drwxr-xr-x
    return [re.sub('[d-](?:[r-][w-][x-]){3}', '', line, 1) for line in list]

# for javap result
def remove_linenum(list):    
    return [re.sub('(.*?)(#\d+[.:]?#?\d+?\s+)', r'\1', re.sub('^\s+#?\d+:?\s+', '', line, 1), 1) for line in list]

# for 'file list'
def remove_timestamps_long(list): 
    # 2015-10-06 03:37:40.000000
    return [re.sub(' \d{4}\-\d{2}\-\d{2} \d{2}:\d{2}:\d{2}\.\d{6} ', '', line, 1) for line in list]

# for 'zipinfo'
def remove_timestamps_short(list):
    # 08-Feb-24 11:12
    return [re.sub('\d{2}\-[A-Z][a-z]{2}\-\d{2} \d{2}:\d{2} ', '', line, 1) for line in list]

def remove_timestamps_metadata(list):
    # last modified: Sun Jan 28 20:54:36 2018
    # last modified: Fri Dec  9 19:33:30 2016
    return [re.sub(', last modified: \w{3} \w{3}\s{1,2}\d{1,2} {1,2}\d{1,2}:\d{1,2}:\d{1,2} {1,2}\d{4}', '', line, 1) for line in list]

# To strip <ins> & <del> for hex output in hexdump
def StripLenExcludeTags(text_with_tags, length_to_strip):
    chars_added = 0
    intag = False
    for i in range(0,len(text_with_tags)):
        if intag == True and text_with_tags[i] != ">" :
            last_tag_char = text_with_tags[i]
            continue;
        if text_with_tags[i] == "<":
            intag = True
            continue;
        if text_with_tags[i] == ">":
            intag = False
            continue;            
        chars_added = chars_added+1
        if chars_added == length_to_strip:            
            # TODO: FIXME, it remove closed tag instead of adding opening in beginning
            # example: 'aaa</ins>aaa' to 'aaaaaa' instead of '<ins>aaa</ins>aaa'
            return str(BeautifulSoup(text_with_tags[i:],"html.parser"))
            

def AddContentElement(parent_tag, tag_name, text, strip_type, text_tag_stripped = None):

    if strip_type == xmlgen.StripTypes.NONE:
        if len(text) > 0 and text[-1] == "✂":
            ET.SubElement(parent_tag, tag_name, cutline='1').text = text[0:-1]
        else:
            ET.SubElement(parent_tag, tag_name).text = text
        return
    
    if strip_type == xmlgen.StripTypes.HEXDUMPUNKNOWN:
        if text_tag_stripped is not None:
            m=xmlgen.HexDumpUnknownDetect.search(text_tag_stripped)
            if m:
                lenstrip = len(m.group(0))
                # Don't handle diffs in hexdump content
                ET.SubElement(parent_tag, tag_name, hexdumpunkown1=m.group(0)).text = StripLenExcludeTags(text, lenstrip)
            else:
                ET.SubElement(parent_tag, tag_name).text = text
        elif xmlgen.HexDumpUnknownDetect.search(text):
            ET.SubElement(parent_tag, tag_name, hexdumpunkown=text[0:51]).text = text[51:]
        else:
            ET.SubElement(parent_tag, tag_name).text = text
    elif strip_type == xmlgen.StripTypes.HEXDUMPBINARY:
        if text_tag_stripped is not None:
            m=xmlgen.HexDumpBinaryDetect.search(text_tag_stripped)
            if m:
                lenstrip = len(m.group(0))
                # Don't handle diffs in hexdump content
                ET.SubElement(parent_tag, tag_name, hexdumpbinary2=m.group(0)).text = StripLenExcludeTags(text, lenstrip)
            else:
                ET.SubElement(parent_tag, tag_name).text = text 
        elif xmlgen.HexDumpBinaryDetect.search(text):
            ET.SubElement(parent_tag, tag_name, hexdumpbinary=text[2:47]).text = text[49:]
        else:
            ET.SubElement(parent_tag, tag_name).text = text

def process( filecontent , file_path_input ):

    if filecontent.find('</html>') == -1 or filecontent.find('<html') == -1:
        #print("Removing File: " + file_path_input + " Reason: Incomplete HTML")
        #os.remove(file_path_input)
        sys.exit("ERROR: Incomplete DiffoScope output")
        
    filecontent = filecontent.replace('<span class="diffponct">·</span>​',' ')
    # Close unclosed span element (in Diffoscope < 64 results)
    filecontent = re.sub("(<span class='source'>[^<]+)<span>", r'\1</span>', filecontent)
    # Remove codebreak
    filecontent = filecontent.replace('​','')
    
    parsed_html = BeautifulSoup(filecontent, 'html.parser')

    # Parse diffoscope version
    
    # TBD - maybe in future, version will be available in META tag
    # version_text = parsed_html.head.find('meta', attrs={'name':'generator'})['content']
    
    version_elm = parsed_html.body.find('div', attrs={'class':'footer'})
    if version_elm is not None:
        version_text = version_elm.text
        matches = re.search("\d+$", version_text)
        if matches is None:
            sys.exit("ERROR1: Fail to detect DiffoScope version")
        else:
            diffoscope_version = int(matches.group(0))
            #print("VER = " , diffoscope_version)
    else:
        sys.exit("ERROR2: Fail to detect DiffoScope version")

    # TODO: Detect unsupported versions
    '''
    if diffoscope_version < 64:
        sys.exit("ERROR: HTML generated with old DiffoScope version %s which isn't supported" % str(diffoscope_version))
    '''
    
    # parse command line
    if diffoscope_version <= 100:
        command_line = parsed_html.title.text
    else: #TBD - maybe in future, commandline will be available elsewhere (span tag / html-comment / etc...)
        command_line = parsed_html.body.find('span', attrs={'id':'commandline'}).text
    #print("command_line =" , command_line)
    
    # parse filepathes
    
    sources = parsed_html.body.find_all('span', attrs={'class':'source'})
    filepath1 = sources[0].text
    filepath2 = sources[1].text
    
    root = ET.Element("diffoscope", type='html_to_xml')
    ET.SubElement(root, "version").text = str(diffoscope_version)
    ET.SubElement(root, "commandline").text = command_line
    ET.SubElement(root, "file1").text = filepath1
    ET.SubElement(root, "file2").text = filepath2
    diff = ET.SubElement(root,"diff")
    cur = diff

    #TODO: CHECK FOR: PYTHONIOENCODING=utf-8

    differences = parsed_html.body.find_all('div', attrs={'class':'difference'})
    lendifferences = len(differences)
    #print("LEN differences = " + str(lendifferences))
    
    close_diff_block = False
    for i in range(1, lendifferences):
        
        if close_diff_block == True or i == 1:
            cur = ET.SubElement(diff, "diffblock")
        close_diff_block = False
            
        # Parse diffheader
        diffheader = differences[i].find('div', attrs={'class':'diffheader'})
        diffsources = diffheader.find_all('span', attrs={'class':'source'})
        lendiffsources = len(diffsources)
        
        if lendiffsources == 2:
            cursource = ET.SubElement(cur, "source1", path1=diffsources[0].text, path2=diffsources[1].text)
        elif diffsources[0].text in xmlgen.processors or xmlgen.ProcessorsMatch.search(diffsources[0].text):
            ET.SubElement(cur, "processor").text = diffsources[0].text
            #print("Processor: " + diffsources[0].text)
        else:
            #print("Source: " + diffsources[0].text)
            cursource = ET.SubElement(cur, "source", extension=''.join(pathlib.Path(diffsources[0].text).suffixes), path=diffsources[0].text)
            
        diffcomments = diffheader.find_all('div', attrs={'class':'comment'})
        for comment in diffcomments:
            ET.SubElement(cur, "comment").text = comment.text
            close_diff_block = True
        
        # Parse table if it's the next element to same parent element.
        table = differences[i].find('table', attrs={'class':'diff'})
        if table is not None and table.parent is not None and diffheader.parent == differences[i].find('table', attrs={'class':'diff'}).parent:
            retcur = cur
            diffin = ET.SubElement(cur,"diffin")
            cur = diffin
            StripType = xmlgen.StripTypes.NONE
            close_diff_block = True
                        
            for pr in retcur.findall("processor"):
                if pr.text.startswith("readelf") and pr.text.find("--hex-dump=") != -1:
                    StripType = xmlgen.StripTypes.HEXDUMPBINARY
                    break
                
            difftrs = differences[i].find_all('tr')
            left_diff_arr = []
            right_diff_arr = []
            
            for difftr in difftrs:
                if difftr['class'][0]=="diffhunk":
                    continue
                if difftr['class'][0]=="diffunmodified":
                    tdr = difftr.find('td', attrs={'class':'diffpresent'})
                    if tdr is None:
                        continue
                    if StripType == xmlgen.StripTypes.NONE and xmlgen.HexDumpUnknownDetect.search(tdr.text[1:-1]):
                        StripType = xmlgen.StripTypes.HEXDUMPUNKNOWN                        
                    AddContentElement(cur , "constant" , tdr.text[1:-1], StripType)
                elif difftr['class'][0]=="diffchanged":
                    linediffs = difftr.find_all('td', attrs={'class':'diffpresent'})
                    lenlinediffs = len(linediffs)
                    if lenlinediffs >= 1:
                        if linediffs[0].text[1:-1].startswith('[ Too much input for diff (SHA1: '):
                            ET.SubElement(cur, "error", type="TooMuchInput").text = linediffs[0].text[1:-1] + " | " + linediffs[1].text[1:-1]
                        else:
                            if StripType == xmlgen.StripTypes.NONE and xmlgen.HexDumpUnknownDetect.search(linediffs[0].text[1:-1]):
                                StripType = xmlgen.StripTypes.HEXDUMPUNKNOWN                           
                            AddContentElement(cur , "change-minus" , str(linediffs[0])[25:-6], StripType, linediffs[0].text[1:-1])
                            left_diff_arr+=[linediffs[0].text[1:-1]]                        
                            if lenlinediffs > 1:
                                AddContentElement(cur , "change-plus" , str(linediffs[1])[25:-6], StripType, linediffs[1].text[1:-1])
                                right_diff_arr+=[linediffs[1].text[1:-1]]
                elif difftr['class'][0]=="diffadded":
                    tdrelm = difftr.find('td', attrs={'class':'diffpresent'})
                    if tdrelm is not None:
                        tdrtext = tdrelm.text[1:-1]
                        if StripType == xmlgen.StripTypes.NONE and xmlgen.HexDumpUnknownDetect.search(tdrtext):
                            StripType = xmlgen.StripTypes.HEXDUMPUNKNOWN                          
                        AddContentElement(cur , "plus" , tdrtext, StripType)
                        left_diff_arr+=[tdrtext]
                elif difftr['class'][0]=="diffdeleted":
                    tdrelm = difftr.find('td', attrs={'class':'diffpresent'})
                    if tdrelm is not None:
                        tdrtext = tdrelm.text[1:-1]
                        if StripType == xmlgen.StripTypes.NONE and xmlgen.HexDumpUnknownDetect.search(tdrtext):
                            StripType = xmlgen.StripTypes.HEXDUMPUNKNOWN                            
                        AddContentElement(cur , "minus" , tdrtext, StripType)
                        right_diff_arr+=[tdrtext]
                elif difftr['class'][0]=="error": #Max Output Probably?
                    tdrelm = difftr.find('td')
                    if tdrelm is not None: 
                        ET.SubElement(cur, "error", type="TrError").text = tdrelm.text
                    
            cur = retcur        
            # Ordering Detections
            # TODO: change sorting types text to ENUM
            right_diff_arr_len = len(right_diff_arr)
            left_diff_arr_len  = len(left_diff_arr)
            if right_diff_arr_len == left_diff_arr_len and right_diff_arr_len > 0:
                this_processor = None
                if retcur.find('processor') is not None:
                    this_processor = retcur.find('processor').text            
                if right_diff_arr_len > 1 :
                    merge_right_left_diff_arr = list(set(right_diff_arr+left_diff_arr))
                    if left_diff_arr_len == len(merge_right_left_diff_arr):
                        ET.SubElement(cur, "order").text = "sorted line order diff"
                    elif this_processor is not None: 
                        if this_processor in ["file list","zipinfo {}"]: #TBD - parse output of zipinfo / file list, thus detect even if more variations apply [order+timestamp+user+umask]
                            right_diff_arr = remove_prems(right_diff_arr)
                            left_diff_arr = remove_prems(left_diff_arr)
                            if left_diff_arr_len == len(list(set(right_diff_arr+left_diff_arr))):
                                ET.SubElement(cur, "order").text = "file-list: sorted file order without prems"
                            elif left_diff_arr_len == len(list(set(remove_timestamps_long(right_diff_arr)+remove_timestamps_long(left_diff_arr)))):
                                ET.SubElement(cur, "order").text = "file-list: sorted file order without long timestamps"            
                            elif left_diff_arr_len == len(list(set(remove_timestamps_short(right_diff_arr)+remove_timestamps_short(left_diff_arr)))):
                                ET.SubElement(cur, "order").text = "file-list: sorted file order without short timestamps"                              
                        elif this_processor == "javap -verbose -constants -s -l -private {}":
                            if left_diff_arr_len == len(list(set(remove_linenum(right_diff_arr)+remove_linenum(left_diff_arr)))):
                                ET.SubElement(cur, "order").text = "javap: sorted file order without line-nums"
                elif right_diff_arr_len == 1:
                    if this_processor == "metadata":
                        if left_diff_arr_len == len(list(set(remove_timestamps_metadata(right_diff_arr)+remove_timestamps_metadata(left_diff_arr)))):
                            ET.SubElement(cur, "metadata").text = "metadata: sorted file order without timestamps"              
                            
            cur = retcur
            
    # Add errors (such as: Max output size reached)
    errors = parsed_html.body.find_all('div', attrs={'class':'error'})
    for error in errors:
        ET.SubElement(diff, "error", type="DivError").text = error.text
                        
    tree = ET.ElementTree(root)
    tree.write(file_path_input+".html.xml")