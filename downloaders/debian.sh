#!/bin/sh
WORKDIR="/data/repbdiffs/datasets/"
ENVS="unstable experimental"
ARCHS="amd64 i386 armhf arm64"
#URLS="https://tests.reproducible-builds.org/debian/dbd https://tests.reproducible-builds.org/debian/dbdtxt"
URLS="https://tests.reproducible-builds.org/debian/dbd"
MAX_FILE_SIZE=4000;
for URL in $URLS;
do
for ENV in $ENVS;
	do
		for ARCH in $ARCHS; do DIRECTORY="$ENV/$ARCH";
cd $WORKDIR
if [ ! -d "$DIRECTORY" ]; then
  echo "Creating directory: $DIRECTORY"
  mkdir -p  "$DIRECTORY"
fi
cd "./$DIRECTORY"
find . -type f -size +${MAX_FILE_SIZE}k -exec truncate -s ${MAX_FILE_SIZE} {} \;
wget -nc -r -nH -nd -np -R "\?*" --no-check-certificate "$URL/$DIRECTORY/?C=S;O=A"
rm -f "index.html?C=S;O=D" "index.html?C=M;O=A"  "index.html?C=N;O=A" "index.html?C=S;O=A" "index.html?C=D;O=A"
find . -type f -size +${MAX_FILE_SIZE}k -exec truncate -s ${MAX_FILE_SIZE} {} \;
done;
done;
done;
