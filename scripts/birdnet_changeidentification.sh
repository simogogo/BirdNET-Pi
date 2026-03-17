#!/usr/bin/env bash

# This scripts allows to change the identification of a Birdnet-pi detection

#################
# SET VARIABLES #
#################

# Define HOME in case environment is not correctly set
HOME="${HOME:-/home/pi}"

# shellcheck disable=sc1091
source /etc/birdnet/birdnet.conf &>/dev/null

# Get arguments
OLDNAME="$1"
NEWNAME="$2"
OUTPUT_TYPE="${3:-debug}"

# New parameter arguments for scoped identifier locking
OLD_SCI_NAME="$4"
DATE="$5"
TIME="$6"

# Fixed values
LABELS_FILE="$HOME/BirdNET-Pi/model/labels.txt"
DB_FILE="$HOME/BirdNET-Pi/scripts/birds.db"
DETECTIONS_TABLE="detections"

###################
# VALIDITY CHECKS #
###################

# Check if files exist
if [ ! -f "$LABELS_FILE" ]; then echo "$LABELS_FILE doesn't exist, exiting" && exit 1; fi
if [ ! -f "$DB_FILE" ]; then echo "$DB_FILE doesn't exist, exiting" && exit 1; fi

# Check if inputs are valid
if [[ "$1" != *"."* ]]; then
  echo "The first argument should be a filename starting with the common name of the bird and finishing by the file extension!"
  echo "Instead, it is : $1"
  exit 1
elif [[ "$2" != *"_"* ]]; then
  echo "The second argument should be in the format : \"scientific name_common name\""
  echo "Instead, it is : $2"
  exit 1
fi

# Check if $NEWNAME is found in the file $LABELS_FILE
if ! grep -q "$NEWNAME" "$LABELS_FILE"; then
    echo "Error: $NEWNAME not found in $LABELS_FILE"
    exit 1
fi

# Check if the common name as the same as the first
OLDNAME_space="${OLDNAME//_/ }"
if [[ "${OLDNAME_space%%-*}" == "${NEWNAME#*_}" ]]; then
    echo "Error: $OLDNAME has the same common name as $NEWNAME"
    exit 1
fi

##################
# EXECUTE SCRIPT #
##################

# Intro
[[ "$OUTPUT_TYPE" == "debug" ]] && echo "Starting to modify $OLDNAME to $NEWNAME"

# Get the line where the column "File_Name" matches exactly $OLDNAME
SQL_QUERY="SELECT Sci_Name, Com_Name, Date FROM $DETECTIONS_TABLE WHERE File_Name = '$OLDNAME'"
if [ -n "$OLD_SCI_NAME" ]; then SQL_QUERY="$SQL_QUERY AND Sci_Name = '$OLD_SCI_NAME'"; fi
if [ -n "$DATE" ]; then SQL_QUERY="$SQL_QUERY AND Date = '$DATE'"; fi
if [ -n "$TIME" ]; then SQL_QUERY="$SQL_QUERY AND Time = '$TIME'"; fi
SQL_QUERY="$SQL_QUERY LIMIT 1;"

IFS='|' read -r OLDNAME_sciname OLDNAME_comname OLDNAME_date < <(sqlite3 "$DB_FILE" "$SQL_QUERY")

if [[ -z "$OLDNAME_sciname" ]]; then
    echo "Error: No line matching $OLDNAME (and provided filters) in $DB_FILE"
    exit 1
fi

# Extract the part before the _ from $NEWNAME
NEWNAME_comname="${NEWNAME#*_}"
NEWNAME_sciname="${NEWNAME%%_*}"

# Replace spaces with underscores, and ' with "" (same logic as helpers.py)
NEWNAME_comname_safe="$(echo "$NEWNAME_comname" | tr -d "'" | tr ' ' '_')"
OLDNAME_comname_safe="$(echo "$OLDNAME_comname" | tr -d "'" | tr ' ' '_')"

# Replace OLDNAME_comname_safe with NEWNAME_comname_safe in OLDNAME
NEWNAME_filename="${OLDNAME//$OLDNAME_comname_safe/$NEWNAME_comname_safe}"

[[ "$OUTPUT_TYPE" == "debug" ]] && echo "This script will change the identification $OLDNAME from $OLDNAME_comname to ${NEWNAME#*_}"

########################
# EXECUTE : MOVE FILES #
########################

# Check if more rows share the same file name
COUNT=$(sqlite3 "$DB_FILE" "SELECT COUNT(*) FROM $DETECTIONS_TABLE WHERE File_Name = '$OLDNAME';")
[[ "$OUTPUT_TYPE" == "debug" ]] && echo "Shared rows count for $OLDNAME: $COUNT"

FILE_PATH="$HOME/BirdSongs/Extracted/By_Date/$OLDNAME_date/$OLDNAME_comname_safe/$OLDNAME"

if [[ -f $FILE_PATH ]]; then
    # Ensure the new directory exists
    NEW_DIR="$HOME/BirdSongs/Extracted/By_Date/$OLDNAME_date/$NEWNAME_comname_safe"
    mkdir -p "$NEW_DIR"
    
    if [ "$COUNT" -gt 1 ]; then
        # Copy file if others share references
        cp "$FILE_PATH" "$NEW_DIR/$NEWNAME_filename"
        cp "$FILE_PATH".png "$NEW_DIR/$NEWNAME_filename".png
        [[ "$OUTPUT_TYPE" == "debug" ]] && echo "Files COPIED (multiple detections share file)!"
    else
        # Move file if it's uniquely owned
        mv "$FILE_PATH" "$NEW_DIR/$NEWNAME_filename"
        mv "$FILE_PATH".png "$NEW_DIR/$NEWNAME_filename".png
        [[ "$OUTPUT_TYPE" == "debug" ]] && echo "Files MOVED (unique ownership)!"
    fi
else
    echo "Error: File $FILE_PATH does not exist"
fi

###################################
# EXECUTE : UPDATE DATABASE FILES #
###################################

# Update the database scoped row
SQL_UPDATE="UPDATE $DETECTIONS_TABLE SET Sci_Name = '$NEWNAME_sciname', Com_Name = '$NEWNAME_comname', Confidence = '0', File_Name = '$NEWNAME_filename' WHERE File_Name = '$OLDNAME'"
if [ -n "$OLD_SCI_NAME" ]; then SQL_UPDATE="$SQL_UPDATE AND Sci_Name = '$OLD_SCI_NAME'"; fi
if [ -n "$DATE" ]; then SQL_UPDATE="$SQL_UPDATE AND Date = '$DATE'"; fi
if [ -n "$TIME" ]; then SQL_UPDATE="$SQL_UPDATE AND Time = '$TIME'"; fi
SQL_UPDATE="$SQL_UPDATE;"

sqlite3 "$DB_FILE" "$SQL_UPDATE"

[[ "$OUTPUT_TYPE" == "debug" ]] && echo "Database entry removed"

[[ "$OUTPUT_TYPE" == "debug" ]] && echo "All done!"
