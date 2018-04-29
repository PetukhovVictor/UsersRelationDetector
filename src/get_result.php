<?

$memcacheD = new \Memcached();
$memcacheD->addServer('localhost', 11211);

$memcacheD->flush();

$data = $memcacheD->get("job_{$argv[1]}_result");

print_r($data);
