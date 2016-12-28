# TBD - Parsing of DiffoScope xml output (XML output not implemented yet in DiffoScope)
# Currently it parse the XML generated from rid.py script from TXT or HTML DiffoScope outputs

import json
import os 
import xml.etree.cElementTree as ET
import enum
from sqlalchemy import MetaData, Table, Column, BigInteger, SmallInteger, Integer, Enum, String, Text, ForeignKey, create_engine    

class XMLTypes(enum.Enum):
    HTML, TXT, XML = range(3)
    
class Envs(enum.Enum):
    TESTING = "testing"
    UNSTABLE = "unstable"
    EXPERIMENTAL = "experimental"

class Archs(enum.Enum):
    i386 = "i386"
    amd64 = "amd64"
    arm64 = "arm64"
    armhf = "armhf"

class PackagesTypes(enum.Enum):
    DEBIAN = "DEBIAN"
    FDROID = "FDROID"
    
def process(filecontent , file_path_input):
    root = ET.fromstring(filecontent)

    if root.attrib['type'] == 'html_to_xml':
        xmltype = XMLTypes.HTML
    elif root.attrib['type'] == 'txt_to_xml':
        xmltype = XMLTypes.TXT
    else:
        xmltype = XMLTypes.XML
        sys.exit('currently only transformed TXT & HTML supported')
        
    # Create tables for DB
    metadata = MetaData()
    
    DiffFiles = Table(
        "DiffFiles", metadata,
        Column("diff_id", BigInteger().with_variant(Integer, "sqlite"), primary_key=True),
        Column("diff_type", Enum(XMLTypes), nullable=False),
        Column("diffoscope_version", SmallInteger, nullable=True),
        Column("command_line", Text, nullable=True),
        Column("filepath1", String(250), nullable=True),
        Column("filepath2", String(250), nullable=True),
        Column("package_id", BigInteger().with_variant(Integer, "sqlite"), ForeignKey('Packages.package_id')),
    )


    Packages = Table(
        "Packages", metadata,
        Column("package_id", BigInteger().with_variant(Integer, "sqlite"), primary_key=True),
        Column("package_name", String(250), nullable=False),
        Column("package_version", String(100), nullable=False),
        Column("package_type", Enum(PackagesTypes), nullable=True),
        Column("environment", Enum(Envs), nullable=True),
        Column('arch', Enum(Archs), nullable=True)        
    )

    Blocks = Table(
        "Blocks", metadata,
        Column("block_id", BigInteger().with_variant(Integer, "sqlite"), primary_key=True),
        Column("diff_id", BigInteger().with_variant(Integer, "sqlite"), ForeignKey('DiffFiles.diff_id')),
        # Sources / Errors / Comments / Processors / Orders
        Column("source", String(250), nullable=True),
        Column("source_tree", Text, nullable=True),
        Column("processors", Text, nullable=True),
        Column("comments", Text, nullable=True),
        Column("ordering",  String(250), nullable=True),        
    )    
    
    Issues = Table(
        "Issues", metadata,
        Column("issue_id", BigInteger().with_variant(Integer, "sqlite"), primary_key=True),
        Column("block_id", BigInteger().with_variant(Integer, "sqlite"), ForeignKey('Blocks.block_id')),
        Column("issue_type", String(250), nullable=False), #todo: change to ENUM or id key to other issuesTypes table
        Column("issue_data", Text, nullable=False), #JSON?
    )
    
    engine = create_engine('sqlite:///RID.sqlite')
    metadata.create_all(engine)

    # Data for DiffFiles Table

    filepath1 = root.find('file1').text
    filepath2 = root.find('file2').text

    if xmltype == XMLTypes.HTML:
        diffoscope_version = root.find('version').text
        command_line_used  = root.find('commandline').text

    # Add Entry to Packages Table if known Package (currently FDROID / DEBIAN Only)
    
    package_id = None
    filename = os.path.basename(filepath1)
    if filepath1[0:40] == "/srv/reproducible-results/rbuild-debian-":
      package_type = PackagesTypes.DEBIAN    
      underscore_arch = filename.rindex("_")
      underscore_version = filename[0:underscore_arch].rindex("_")
      
      package_target_arch = filename[underscore_arch+1:filename.rindex(".")]
      package_name = filename[0:underscore_version]
      package_version = filename[underscore_version+1:underscore_arch]
      # TODO: ENV DETECT [environment=DebianEnvs.??]
      package_id=engine.execute(Packages.insert().values(package_name=package_name,package_version=package_version,arch=package_target_arch,package_type=package_type)).inserted_primary_key[0]
    elif filepath1[0:13] == "/code/fdroid/":
       package_type = PackagesTypes.FDROID    
       underscore_version = filename.rindex("_")
       package_name = filename[0:underscore_version]
       package_version = filename[underscore_version+1:filename.rindex(".")]
       package_id=engine.execute(Packages.insert().values(package_name=package_name,package_version=package_version,package_type=package_type)).inserted_primary_key[0]

    '''
    else: # Debug message?
       print('Currently FDROID & Debian support only')
    '''
    
    # Add Entry in DiffFiles Table
    
    diff_id = engine.execute(DiffFiles.insert().values(diff_type=xmltype,diffoscope_version=int(diffoscope_version),command_line=command_line_used,filepath1=filepath1,filepath2=filepath2,package_id=package_id)).inserted_primary_key[0]

    diverror = root.find('.//error[@type="DivError"]')
    if diverror is not None:
        print("DivError = " + diverror.text)
        #issue_data = json.dumps({"type": "GlobalError", "text": diverror.text})
        #engine.execute(Issues.insert().values(block_id=block_id,issue_type="error",issue_data=issue_data))
    
    blocks = root.findall('.//diffblock')
    for block in blocks:
        
        sources=block.findall('.//source')
        sources1=block.findall('.//source1')
        lensources=len(sources)
        lensources1=len(sources1)
        source_tail=""
        last_source=""
        if lensources > 0:
            for source in sources:
                source_tail += source.attrib['path'] + "/"
            source_tail=source_tail[0:-1]
            last_source = sources[lensources-1].attrib['path']
        
        if lensources1 > 0:
            for source1 in sources1:
                source_tail += source1.attrib['path1'] + " [=] " + source1.attrib['path2'] + "/" # vs.
            source_tail=source_tail[0:-1]
            last_source = sources1[lensources1-1].attrib['path1'] + " [=] " + sources1[lensources1-1].attrib['path2']
        
        differences=block.findall('.//difference')            
        processors=block.findall('.//processor')
        comments=block.findall('.//comment')
        orders=block.findall('.//order')
        
        differences_arr = []        
        processors_arr = []     
        comments_arr = []
        orders_arr = []

        for difference in differences:
            differences_arr.append(difference.text)
        for processor in processors:
            processors_arr.append(processor.text)
        for comment in comments:
            comments_arr.append(comment.text)
        for order in orders:
            orders_arr.append(order.text)

        differences_arr = json.dumps(differences_arr)
        processors_arr = json.dumps(processors_arr)
        comments_arr = json.dumps(comments_arr)
        orders_arr = json.dumps(orders_arr)
                
        block_id=engine.execute(Blocks.insert().values(diff_id=diff_id,source=last_source,source_tree=source_tail,processors=processors_arr,comments=comments_arr,ordering=orders_arr)).inserted_primary_key[0]

        diffin = block.find('.//diffin')
        if diffin is not None:
            cutlines = diffin.findall('.//*[@cutline]')
            for cutline in cutlines:
                issue_data = json.dumps({"tag": cutline.tag, "text": cutline.text})
                engine.execute(Issues.insert().values(block_id=block_id,issue_type="linecut",issue_data=issue_data))
                
            errors = diffin.findall('.//error')
            for error in errors:
                issue_data = json.dumps({"type": error.attrib['type'], "text": error.text})
                engine.execute(Issues.insert().values(block_id=block_id,issue_type="error",issue_data=issue_data))

            binaryunknowncompare = diffin.find('.//*[@hexdumpunkown]')
            if binaryunknowncompare is not None:
                issue_data = json.dumps({"tag": binaryunknowncompare.tag, "text": binaryunknowncompare.text})
                engine.execute(Issues.insert().values(block_id=block_id,issue_type="binaryunknowncompare",issue_data=issue_data))
            
            binaryunknowncompare = diffin.find('.//*[@hexdumpunkown1]')
            if binaryunknowncompare is not None:
                issue_data = json.dumps({"tag": binaryunknowncompare.tag, "text": binaryunknowncompare.text})
                engine.execute(Issues.insert().values(block_id=block_id,issue_type="binaryunknowncompare1",issue_data=issue_data))