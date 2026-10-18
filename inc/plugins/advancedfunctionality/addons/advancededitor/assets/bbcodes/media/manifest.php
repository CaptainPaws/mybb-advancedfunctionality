<?php
return [
 'id'=>'media','title'=>'Изображения','tags'=>[],
 'runtime'=>['activation'=>'click','requires'=>[]],
 'buttons'=>[
  ['cmd'=>'image','title'=>'Изображение','handler'=>'media','iconSvg'=>'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8" cy="8" r="1.5"/><path d="m3 17 5-5 4 4 3-3 6 6"/></svg>'],
 ],
 'assets'=>['js'=>['bbcodes/media/media.js'],'css'=>['bbcodes/media/media.css']],
];
