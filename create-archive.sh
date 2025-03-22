#!/bin/bash

FORCE=false
if [ "$1" == "--force" ]; then
  FORCE=true
fi

if [ -d "BTCPay" ]; then
  if [ "$FORCE" == true ]; then
    rm -r BTCPay
  else
    echo "Directory BTCPay already exists. Use --force to overwrite."
    exit 1
  fi
fi

if [ -f "BTCPay.zip" ]; then
  if [ "$FORCE" == true ]; then
    rm BTCPay.zip
  else
    echo "File BTCPay.zip already exists. Use --force to overwrite."
    exit 1
  fi
fi

mkdir BTCPay
cp -r assets BTCPay
cp BTCPay.php BTCPay
cp routes.php BTCPay
cp README.md BTCPay
cp LICENSE BTCPay
zip -r BTCPay.zip BTCPay
rm -r BTCPay
echo "Archive created successfully."