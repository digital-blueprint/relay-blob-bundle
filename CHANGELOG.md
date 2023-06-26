# v0.1.6
* Add "binary" option to GETALL action, which returns 302 redirect links to the binary 
* Introduce new config options for email reporting

# v0.1.5
* Add concrete implementation for /files/{identifier} endpoint
  * A request to /files/{identifier} returns metadata of the file
  * A request to /files/{identifier} with an parameter binary=1 returns a 302 redirect to the file binary download
* Code cleanup

# v0.1.3
* Retrieve link expire time from config 
* GET requests are now validated by signature in url, and validUntil date
* remove signature from header
* signature now signs a sha2 checksum over the url (to shorten the signature)

# v0.1.2
* remove phpunit functions (assertNotNull)

# v0.1.1
 * add signature to url, temporarily also allow signature in header
 * remove echos, remove dumps
 * update to api-platform 2.7