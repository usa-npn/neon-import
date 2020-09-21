#!/bin/sh

cd /usr/local/scripts/neon-import/

cd R

Rscript NEON-data-script.R

cd ..

php neon-import.php > ./run.txt 
