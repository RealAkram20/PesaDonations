#!/bin/bash
# Copy the plugin working tree into the throwaway site as a real folder.
DEST=/d/pdwp/site/wp-content/plugins/pesa-donations
mkdir -p "$DEST"
tar --exclude=.git --exclude=docs --exclude=graphify-out -C /d/xampp/htdocs/pesadonation -cf - . | tar -xf - -C "$DEST"
diff -rq --exclude=.git --exclude=docs --exclude=graphify-out /d/xampp/htdocs/pesadonation "$DEST" >/dev/null && echo "synced"
