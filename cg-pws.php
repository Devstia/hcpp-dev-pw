<?php
/**
// openssl  genrsa -out ./pws.key 2048 2>&1
// openssl req -x509 -new -nodes -key ./pws.key -sha256 -days 825 -out ./pws.crt -subj "/C=US/ST=California/L=San Diego/O=CodeGarden PWS/OU=Customers/CN=pws.localhost" 2>&1
