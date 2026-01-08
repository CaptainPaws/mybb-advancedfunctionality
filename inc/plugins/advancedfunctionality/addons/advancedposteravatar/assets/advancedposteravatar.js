(function($){
  'use strict';

  function unicodeCharAt(str, idx){
    var first = str.charCodeAt(idx);
    if(first >= 0xD800 && first <= 0xDBFF && str.length > idx + 1){
      var second = str.charCodeAt(idx + 1);
      if(second >= 0xDC00 && second <= 0xDFFF){
        return str.substring(idx, idx + 2);
      }
    }
    return str[idx];
  }

  function unicodeSlice(str, start, end){
    var out = '';
    var sIdx = 0, uIdx = 0;
    while(sIdx < str.length){
      var ch = unicodeCharAt(str, sIdx);
      if(uIdx >= start && uIdx < end) out += ch;
      sIdx += ch.length;
      uIdx++;
    }
    return out;
  }

  $.fn.apaLetterAvatar = function(options){
    var colors = ["#1abc9c","#16a085","#f1c40f","#f39c12","#2ecc71","#27ae60","#e67e22","#d35400","#3498db","#2980b9","#e74c3c","#c0392b","#9b59b6","#8e44ad","#bdc3c7","#34495e","#2c3e50","#95a5a6","#7f8c8d","#ec87bf","#d870ad","#f69785","#9ba37e","#b49255","#a94136"];
    return this.each(function(){
      var e = $(this);
      var settings = $.extend({
        name: 'User',
        seed: 0,
        charCount: 1,
        textColor: '#ffffff',
        height: parseInt(e.attr('height') || '44', 10),
        width: parseInt(e.attr('width') || '44', 10),
        fontSize: Math.round((parseInt(e.attr('width') || '44', 10)) * 0.6),
        fontWeight: 600,
        fontFamily: 'Helvetica, Arial, sans-serif'
      }, options || {});

      settings = $.extend(settings, e.data());

      var name = (settings.name || 'U').toString();
      var c = unicodeSlice(name, 0, settings.charCount).toUpperCase();
      var colorIndex = Math.floor((c.charCodeAt(0) + settings.seed) % colors.length);
      var finalColor = colors[colorIndex];

      var svg =
        '<svg xmlns="http://www.w3.org/2000/svg" width="'+settings.width+'" height="'+settings.height+'">' +
          '<rect width="100%" height="100%" fill="'+finalColor+'"/>' +
          '<text x="50%" y="50%" dy="0.35em" text-anchor="middle" fill="'+settings.textColor+'" ' +
            'font-family="'+settings.fontFamily+'" font-size="'+settings.fontSize+'" font-weight="'+settings.fontWeight+'">' +
            String(c).replace(/</g,'&lt;').replace(/>/g,'&gt;') +
          '</text>' +
        '</svg>';

      var svg64 = window.btoa(unescape(encodeURIComponent(svg)));
      e.attr('src', 'data:image/svg+xml;base64,' + svg64);
    });
  };

  $(function(){
    $('.apa_bg').apaLetterAvatar();
  });

})(jQuery);
