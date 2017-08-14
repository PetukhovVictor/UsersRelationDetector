<?

$memcacheD = new \Memcached();
$memcacheD->addServer('localhost', 11211);

$memcacheD->flush();

$data = $memcacheD->get("job_{$argv[1]}_result");

//$memcacheD->delete("vk_queries_waiting_params");

//print_r($memcacheD->getStats());

print_r($data);

file_put_contents('test.txt', gzcompress(json_encode($data)));
