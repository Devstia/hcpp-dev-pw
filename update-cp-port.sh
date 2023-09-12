#!/bin/bash
port="$1"

# Update HestiaCP admin password
/usr/local/hestia/bin/v-change-sys-port "$port"
