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
