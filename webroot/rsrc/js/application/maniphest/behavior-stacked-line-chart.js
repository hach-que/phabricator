/**
 * @provides javelin-behavior-stacked-line-chart
 * @requires javelin-behavior
 *           javelin-dom
 *           javelin-vector
 */

JX.behavior('stacked-line-chart', function(config) {

  var h = JX.$(config.hardpoint);
  var p = JX.$V(h);
  var d = JX.Vector.getDim(h);
  var mx = 60;
  var my = 30;

  var r = new Raphael(h, d.x, d.y);

  var newx = [];
  var newy = [];
  var lastnull = [];
  var ccc = 0;
  for (var yyy in config.y) {
    var yylist = [];
    var xxlist = [];
    var nulllist = [];
    var idx = 0;
    var lastyyv = null;
    for (var yyv in config.y[yyy]) {
      var yyi = config.y[yyy][yyv]
      if (yyi === null) {
        if (config.lead[yyy]) {
          if (nulllist.length > 0 && !nulllist[nulllist.length - 1]) {
            if (config.x[0][yyv] === undefined) {
              console.log('attempted to access yyv ' + yyv + ' for non-existant x axis');
              return false;
            }
            
            // Last item was not null, create an entry at this position
            // to "lead out" on the graph.
            if (ccc == 0 || !config.stacked[yyy]) {
              //xxlist.push(config.x[0][yyv]);
              //yylist.push(0);
            } else {
              if (newy[ccc - 1][idx] != null) {
                xxlist.push(config.x[0][yyv]);
                yylist.push(newy[ccc - 1][idx]);
              }
            }
          }
        }
        
        nulllist.push(true);
      } else {
        if (config.lead[yyy]) {
          if (nulllist.length > 0 && nulllist[nulllist.length - 1]) {
            if (config.x[0][yyv] === undefined) {
              console.log('attempted to access lastyyv ' + lastyyv + ' for non-existant x axis');
              return false;
            }
            
            // Last item was null, create an entry at the last
            // position so we "lead in" on the graph.
            if (ccc == 0 || !config.stacked[yyy]) {
              xxlist.push(config.x[0][lastyyv]);
              yylist.push(0);
            } else {
              if (newy[ccc - 1][idx] != null) {
                xxlist.push(config.x[0][lastyyv]);
                yylist.push(newy[ccc - 1][idx]);
              }
            }
          }
        }
        
        if (config.x[0][yyv] === undefined) {
          console.log('attempted to access yyv ' + yyv + ' for non-existant x axis');
          return false;
        }
        
        if (ccc == 0 || !config.stacked[yyy]) {
          xxlist.push(config.x[0][yyv]);
          yylist.push(yyi);
          nulllist.push(false);
        } else {
          xxlist.push(config.x[0][yyv]);
          yylist.push(newy[ccc - 1][idx] + yyi);
          nulllist.push(false);
        }
      }
      idx++;
      lastyyv = yyv;
    }
    idx = 0;
    ccc++;
    newx.push(xxlist);
    newy.push(yylist);
    lastnull.push(nulllist);
  }

  var l = r.linechart(
    mx, my,
    d.x - (2 * mx), d.y - (2 * my),
    newx,
    newy,
    {
      nostroke: false,
      axis: '0 0 1 1',
      shade: false,
      smooth: false,
      gutter: 1,
      colors: config.colors || ['#2980b9']
    });

  function format(value, type) {
    switch (type) {
      case 'epoch':
        return new Date(parseInt(value, 10) * 1000).toLocaleDateString();
      case 'int':
        return parseInt(value, 10);
      default:
        return value;
    }
  }

  // Format the X axis.

  var n = 2;
  var ii = 0;
  var text = l.axis[0].text.items;
  for (var k in text) {
    if (ii++ % n) {
      text[k].attr({text: ''});
    } else {
      var cur = text[k].attr('text');
      var str = format(cur, config.xformat);
      text[k].attr({text: str});
    }
  }

  // Show values on hover.

  l.hoverColumn(function() {
    this.tags = r.set();
    for (var yy = 0; yy < config.y.length; yy++) {
      var yvalue = 0;
      for (var ii = 0; ii < config.x[0].length; ii++) {
        if (config.x[0][ii] > this.axis) {
          break;
        }
        if (config.y[yy][ii] == null) {
          yvalue = null;
        } else {
          yvalue = format(config.y[yy][ii], config.yformat);
        }
      }
      
      if (yvalue == null) {
        continue;
      }

      var xvalue = format(this.axis, config.xformat);

      var tag = r.tag(
        this.x,
        this.y[yy],
        [xvalue, yvalue].join('\n'),
        180,
        24);
      tag
        .insertBefore(this)
        .attr([{fill : '#fff'}, {fill: '#000'}]);

      this.tags.push(tag);
    }
  }, function() {
    this.tags && this.tags.remove();
  });

});
