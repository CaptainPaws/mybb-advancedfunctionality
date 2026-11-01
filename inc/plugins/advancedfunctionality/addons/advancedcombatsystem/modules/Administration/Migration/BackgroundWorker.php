<?php
if(!defined('IN_MYBB'))die('No direct access');
/** No verified autonomous broker exists in this installation contract. Fail closed. */
final class AF_ACS_MigrationBackgroundWorker
{
    public function status():array {return ['status'=>'BLOCKED','code'=>'worker_unavailable','detail'=>'Автономный серверный исполнитель недоступен. Нужен проверенный supervisor/scheduler с устойчивым идентификатором задания, heartbeat и чтением EngineMigration journal. HTTP-task и fire-and-forget не подходят.'];}
    public function submit():never {throw new RuntimeException('worker_unavailable');}
}
