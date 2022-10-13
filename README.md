# Smart Relay PHP SDK
Xtend Smart Relay PHP SDK 

This contain PHP SDK for Generic Smart Relay

## Feature
- [x] Connect to Xtend Smart Relay socket
- [x] Push command for turning on/off selected Pin
- [ ] Read Pin status
- [ ] Read Switch

## Usage
```php
$host = '192.168.100.18';
$port = 50000;
$smartRelay = new \Xtend\Device\SmartRelay($host, $port);
try {
    $smartRelay->open();                                   // open connection to socket
    var_dump($smartRelay->isConnect());                    // check device status
    var_dump($smartRelay->push(3, SmartRelay::PIN_ON));    // turn on 3rd pin
    var_dump($smartRelay->push(3, SmartRelay::PIN_OFF));   // turn off 3rd pin
    $smartRelay->close();                                  // close cocket connection
} catch (\RuntimeException $e) {
    echo $e->getMessage();
}

```
