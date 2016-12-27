#!/bin/sh
WORKDIR="/data/repbdiffs/datasets/"
DIRECTORY="fdroid"
cd $WORKDIR
if [ ! -d "$DIRECTORY" ]; then
  echo "Creating directory: $DIRECTORY"
  mkdir -p  "$DIRECTORY"
fi
cd "./$DIRECTORY"
#wget -nc -r -nH -nd -np -R "\?*" --no-check-certificate http://37.218.242.117/ -A txt
wget -nc -r -nH -nd -np -R "\?*" --no-check-certificate "http://37.218.242.117/?C=S;O=A" -A html
# TODO: ADD TRUNCATE
