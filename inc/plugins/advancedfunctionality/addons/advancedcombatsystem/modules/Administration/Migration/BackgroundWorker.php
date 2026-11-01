<?php
if(!defined('IN_MYBB'))die('No direct access');
/** No verified autonomous broker exists in this installation contract. Fail closed. */
final class AF_ACS_MigrationBackgroundWorker
{
    public function status():array {return ['status'=>'BLOCKED','code'=>'worker_unavailable','detail'=>'Автономный серверный исполнитель недоступен. Это альтернативный способ для операций, которые нельзя безопасно ограничить рамками ACP-запроса. Нужен проверенный номер задания и устойчивый журнал результата; HTTP-task и fire-and-forget не подходят.'];}
    public function submit():never {throw new RuntimeException('worker_unavailable');}
}
