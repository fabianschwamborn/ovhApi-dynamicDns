# ovhApi-dynamicDns

Disclaimer: I am not working with OVH nor is this anything officially made by them. I just wrote this project to enable DynDNS for our secondary DSL line which has a speedport, which is not compatible with the OVH dyndns api (my experience).
The script manages to update various subdomains for multiple cients.
It Supports both IPv4 (A records) and IPv6 (AAAA records) simultaneously. Currently i havent implemented filter, like only use IPvX for clienty Y. (May be added upon request or need)

## Features

- Updates both A and AAAA records in one request
- Handles mixed IPv4/IPv6 input formats (myip=1.2.3.4,2001:db8::1)
- Basic authentication with hashed passwords
- TTL management for DNS records
- Proper error handling and logging
- DynDNS protocol compatible responses

## Requirements

- Webspace hosted by OVH
- Domain and DNS by OVH

## Installation

1. Clone repository
2. Copy configuration files:


Required OVH API Permissions
GET/POST /domain/zone/[domain]/record
GET/PUT/DELETE /domain/zone/[domain]/record/*
POST /domain/zone/[domain]/refresh



Response Codes are oriented on typial ddns protocols
good [IP]: Record created/updated
nochg [IP]: Record unchanged
badauth: Authentication failed
911 [error]: Server error