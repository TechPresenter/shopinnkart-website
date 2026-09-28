Put a MaxMind GeoLite2 database in this folder to record where visits
come from. The analytics collector looks for these names, in this order:

    GeoLite2-City.mmdb
    GeoLite2-Country.mmdb

It also needs the maxmind-db reader, unpacked so that this file exists:

    C:\xampp\htdocs\ecomweb/vendor/maxmind-db/reader/src/MaxMind/Db/Reader.php

Then set Settings > Analytics > Visitor location to MaxMind GeoLite2.
That screen reports whether the file is found, readable and current.

Nothing in this folder is served over HTTP (see .htaccess), and no
visitor address is ever written to it: the lookup happens in memory and
only the country - and the city, if you ask for it - is stored.

GeoLite2 is created by MaxMind, available from https://www.maxmind.com.
