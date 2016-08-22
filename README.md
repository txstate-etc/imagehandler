# imagehandler

Mount in the following:
* A cache volume into `/var/cache/resize`.
* An auth.php file into `/var/www/html/auth.php`.
    * This file is a list of domains, mapped to usernames and passwords, so 
    that the imagehandler server can get access to authorized images. Assumes 
    basic HTTP authentication.
