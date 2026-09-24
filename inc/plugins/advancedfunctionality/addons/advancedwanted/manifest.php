<?php
return [
 'id'=>'advancedwanted','name'=>'AdvancedWanted','description'=>'Каталог заявок на нужных персонажей.','version'=>'1.0.0','author'=>'CaptainPaws','bootstrap'=>'advancedwanted.php',
 'admin'=>['slug'=>'advancedwanted','controller'=>'admin.php','title'=>'AdvancedWanted','icon'=>'fas fa-user-plus','order'=>35],
 'theme_stylesheets'=>[[
   'id'=>'advancedwanted_main','file'=>'assets/advancedwanted.css','stylesheet_name'=>'af_advancedwanted.css',
   'attach'=>[['file'=>'global'],['file'=>'wanted.php']],
 ]],
 'lang'=>['russian'=>['front'=>['af_wanted_name'=>'Нужные персонажи'],'admin'=>['af_wanted_group'=>'AdvancedWanted']], 'english'=>['front'=>['af_wanted_name'=>'Wanted characters'],'admin'=>['af_wanted_group'=>'AdvancedWanted']]],
];
