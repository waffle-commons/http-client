#!/bin/bash

# --- Configuration ---
# The main placeholder string used throughout the template
PLACEHOLDER="{COMPONENT_NAME}"
# Add other placeholders here if needed in the future
# PLACEHOLDER_NS_VENDOR="{NAMESPACE_VENDOR}" # Example
# PLACEHOLDER_NS_COMPONENT="{NAMESPACE_COMPONENT}" # Example

# --- Script Logic ---
set -e # Exit immediately if a command exits with a non-zero status.

# Check if a component name was provided
if [ -z "$1" ]; then
  echo "Usage: $0 <ComponentName>" >&2
  echo "  <ComponentName> should be the name of your new component in PascalCase (e.g., Http, YamlParser)." >&2
  exit 1
fi

NEW_NAME="$1"

# Basic validation for PascalCase (recommended for PSR-4 class names)
if ! [[ "$NEW_NAME" =~ ^[A-Z][a-zA-Z0-9]*$ ]]; then
 echo "Warning: ComponentName '$NEW_NAME' is not in standard PascalCase format." >&2
 exit 1
fi

echo "--------------------------------------------------"
echo "Configuring Waffle Commons Component: $NEW_NAME"
echo "Placeholder to replace: $PLACEHOLDER"
echo "--------------------------------------------------"

# 1. Replace placeholder within file content
# Uses Perl for robust in-place editing across different systems (Linux/macOS)
# Excludes .git directory, vendor (if exists), and this script itself.
echo "Step 1: Replacing placeholder '$PLACEHOLDER' with '$NEW_NAME' in file contents..."
find . -type f -not -path './.git/*' -not -path './vendor/*' -not -name "$(basename "$0")" -print0 | xargs -0 perl -pi -e "s/${PLACEHOLDER}/${NEW_NAME}/g"
echo "Content replacement complete."
echo "--------------------------------------------------"

# 2. Rename files and directories containing the placeholder
# Uses find with -depth to process files/dirs in nested directories first
echo "Step 2: Renaming files and directories containing '$PLACEHOLDER'..."
find . -depth -name "*${PLACEHOLDER}*" -not -path './.git/*' -not -path './vendor/*' -not -name "$(basename "$0")" | while IFS= read -r file_or_dir; do
  # Construct the new name by replacing the placeholder
  new_name=$(echo "$file_or_dir" | sed "s/${PLACEHOLDER}/${NEW_NAME}/g")
  if [ "$file_or_dir" != "$new_name" ]; then
    echo "  Renaming '$file_or_dir' -> '$new_name'"
    # Use mv -- to handle potential filenames starting with -
    mv -- "$file_or_dir" "$new_name"
  fi
done
echo "Renaming complete."
echo "--------------------------------------------------"

# 3. Remove template-specific boilerplate
echo "Step 3: Removing template boilerplate..."
# Check if the directory exists before trying to remove it
if [ -d "images" ]; then
  echo "  Removing 'images/' directory and all its contents (including waffles-commons_logo.png)..."
  rm -rf images/
else
  echo "  'images/' directory not found, skipping cleanup."
fi
echo "Boilerplate removal complete."
echo "--------------------------------------------------"

# 4. Optional: Self-destruct the script after successful execution
# echo "Step 4: Removing configuration script..."
# rm -- "$0"
# echo "Script removed."
# echo "--------------------------------------------------"

echo "Configuration finished successfully for component '$NEW_NAME'."
echo "You may want to:"
echo " - Review the changes (e.g., git status, git diff)."
echo " - Update the 'description' and other relevant fields in composer.json."
echo " - Initialize your own Git history if this is a fresh component."
echo " - Consider removing this script ('rm $0') if you don't need it anymore."
echo "--------------------------------------------------"

exit 0
