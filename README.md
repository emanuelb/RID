# RID
Tool to detect Reproducibility Issues Automatically From Diffoscope output

# INTRO

Detection of bugs that cause builds/packaging to generate non-reproducible artifacts consist of 4 steps:

1. Run the build/packaging process twice (at least) on different systems with many different variations.
2. In case the artifacts are different run DiffoScope on them.
3. Manually analyzing the output of DiffoScope in order to detect what the issues are.
4. Locating the root cause of the issue in the package source (or dependencies if the issue is from toolchain)

The last step after detection is fixing :)

This tool try to automate as much as possible the #3 step.

# How to use

Install Dependencies:

pip3 install beautifulsoup4 sqlalchemy

Run: (currently only HTML output supported)

python3 rid.py diffoscope_txt_or_html_output_file

which will generate XML file, then Run:

python3 rid.py generated_xml_file

which will add the results to RID.sqlite file.
