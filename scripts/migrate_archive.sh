#!/bin/bash

# Configuration
DB_PATH="${HOME}/BirdNET-Pi/scripts/birds.db"
EXTRACTED_DIR="${HOME}/BirdSongs/Extracted/By_Date"
DRY_RUN=false
VERBOSE=false

# Help
usage() {
    echo "Usage: $0 [OPTIONS]"
    echo ""
    echo "Options:"
    echo "  --dry-run   Preview all changes without executing them."
    echo "  --verbose   Show detailed move/update progress."
    echo "  --help      Display this help message."
    echo ""
    echo "This script migrates an old BirdNET-Pi archive into the modern format:"
    echo "1. Moves files from By_Date/Scientific_Name/ to By_Date/YYYY-MM-DD/Safe_Common_Name/"
    echo "2. Strips colons (:) from filenames (e.g., 12:00:00.wav -> 120000.wav)"
    echo "3. Updates the SQL database with the new filenames."
    exit 0
}

# Parse arguments
for arg in "$@"; do
    case $arg in
        --dry-run) DRY_RUN=true ;;
        --verbose) VERBOSE=true ;;
        --help) usage ;;
    esac
done

# Check requirements
if [ ! -f "$DB_PATH" ]; then
    echo "Error: Database not found at $DB_PATH"
    exit 1
fi

if [ ! -d "$EXTRACTED_DIR" ]; then
    echo "Error: Extraction directory not found at $EXTRACTED_DIR"
    exit 1
fi

if [ "$DRY_RUN" = true ]; then
    echo "!!! DRY RUN MODE - No changes will be made !!!"
else
    # Backup DB
    BACKUP_FILE="${DB_PATH}.bak.$(date +%F_%T)"
    cp "$DB_PATH" "$BACKUP_FILE"
    echo "Database backup created: $BACKUP_FILE"
fi

# Function to sanitize common name (Space -> _, Apostrophe -> "")
# Matches PHP: str_replace([' ', "'"], ['_', ''], $row['Com_Name'])
sanitize_com_name() {
    echo "$1" | tr ' ' '_' | tr -d "'"
}

echo "Starting migration..."

# Get all detections from DB
# We need Date, Time, Sci_Name, Com_Name, File_Name
QUERY="SELECT Date, Time, Sci_Name, Com_Name, File_Name FROM detections;"

# Use a temporary file for results to avoid pipe issues with sqlite3 inside the loop
TMP_LIST=$(mktemp)
sqlite3 -separator '|' "$DB_PATH" "$QUERY" > "$TMP_LIST"

COUNTER=0
MOVED=0
UPDATED=0

while IFS='|' read -r DATE TIME SCI_NAME COM_NAME FILE_NAME; do
    [ -z "$FILE_NAME" ] && continue
    ((COUNTER++))

    # 1. Old Path candidate (Sci_Name folder)
    OLD_DIR="${EXTRACTED_DIR}/${SCI_NAME}"
    OLD_PATH="${OLD_DIR}/${FILE_NAME}"
    
    # 2. Target Path definition
    COM_NAME_SAFE=$(sanitize_com_name "$COM_NAME")
    NEW_FILENAME=$(echo "$FILE_NAME" | tr -d ':')
    NEW_DIR="${EXTRACTED_DIR}/${DATE}/${COM_NAME_SAFE}"
    NEW_PATH="${NEW_DIR}/${NEW_FILENAME}"

    # Check if the file exists in the OLD structure
    if [ -f "$OLD_PATH" ]; then
        if [ "$VERBOSE" = true ]; then
            echo "------------------------------------------------"
            echo "Found: $OLD_PATH"
            echo "Target: $NEW_PATH"
        fi

        if [ "$DRY_RUN" = false ]; then
            mkdir -p "$NEW_DIR"
            mv "$OLD_PATH" "$NEW_PATH"
            [ -f "${OLD_PATH}.png" ] && mv "${OLD_PATH}.png" "${NEW_PATH}.png"
            ((MOVED++))
        else
            echo "[DRY-RUN] mkdir -p $NEW_DIR"
            echo "[DRY-RUN] move $FILE_NAME -> $NEW_FILENAME"
        fi
    fi

    # Check if the database needs updating (if filename contains colons)
    if [[ "$FILE_NAME" == *":"* ]]; then
        if [ "$DRY_RUN" = false ]; then
            UPDATE_SQL="UPDATE detections SET File_Name = '${NEW_FILENAME}' WHERE File_Name = '${FILE_NAME}' AND Date = '${DATE}' AND Time = '${TIME}';"
            sqlite3 "$DB_PATH" "$UPDATE_SQL"
            ((UPDATED++))
        else
            echo "[DRY-RUN] UPDATE detections SET File_Name = '$NEW_FILENAME' WHERE File_Name = '$FILE_NAME'"
        fi
    fi

done < "$TMP_LIST"

rm "$TMP_LIST"

echo "------------------------------------------------"
echo "Migration Complete!"
echo "Processed: $COUNTER entries"
if [ "$DRY_RUN" = false ]; then
    echo "Files moved: $MOVED"
    echo "Database entries updated: $UPDATED"
else
    echo "Dry run finished. No changes were made."
fi
