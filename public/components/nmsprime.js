// Own JS NMS Prime File for our specific functions

// Type anywhere to search in global search for keyword
// Escape Keymodifiers and exit with escape
// @author: Christian Schramm
var makeNavbarSearch = function() {
	$('#togglesearch').on('click', function (event) {
	  $("#globalsearch").focus().select();
	});

	$(document).on('keypress', function (event) {
	  if ($('*:focus').length == 0 && event.target.id != 'globalsearch'){
	    var code = (event.keyCode ? event.keyCode : event.which);
	    if (event.which !== 0 && !event.ctrlKey && !event.metaKey && !event.altKey) {
	      $("#togglesearch").click();
	      if(navigator.userAgent.toLowerCase().indexOf('firefox') > -1){
	        $("#globalsearch").val(String.fromCharCode(code));
	        }
	      }
	    }
	});
	console.log("nmsprime.js is working")
	$('#globalsearch').on('keydown', function (event) {
	    var code = (event.keyCode ? event.keyCode : event.which);
	    if (code == 27) {
	      $("#globalsearch").val('');
	      $("#header").removeClass('navbar-search-toggled');
	      $("#globalsearch").removeClass('navbar-search-toggled');
	      $("#globalsearch").blur();
	    }
	});
};

// Keep Sidebar open and Save State and Minify Status of Sidebar
// @author: Christian Schramm
if (typeof(Storage) !== "undefined") {
//save minified s_state
var ministate = localStorage.getItem("minified-state");
if (ministate == "true") {
  $('#page-container').addClass('page-sidebar-minified');
} else {
  $('#page-container').removeClass('page-sidebar-minified');
}
var sitem = localStorage.getItem("sidebar-item");
var chitem = localStorage.getItem("clicked-item");
$('#' + sitem).addClass("expand");
$('#' + sitem + ' .sub-menu ').css("display", "block");
$('#sidebar .sub-menu li').click(function(event) {
    localStorage.setItem("clicked-item", $(this).attr('id'));
    if ($('.page-sidebar-minified') == true) {
      $('#' + sitem).addClass("expand");
    }
});
$('#' + chitem).addClass("active");
}else {
  console.log("sorry, no Web Storage Support - Cant save State of Sidebar")
}




/* This bit can be used on the entire app over all pages and will work for both tabs and pills.
* Also, make sure the tabs or pills are not active by default,
* otherwise you will see a flicker effect at page load.
* Important: Make sure the parent ul has an id. Thanks Alain
* http://stackoverflow.com/posts/16984739/revisions
*/
var saveTabPillState = function() {
  $(function() {
    var json, tabsState;
    $('a[data-toggle="pill"], a[data-toggle="tab"]').on('shown.bs.tab', function(e) {
      var href, json, parentId, tabsState;

      tabsState = localStorage.getItem("tabs-state");
      json = JSON.parse(tabsState || "{}");
      parentId = $(e.target).parents("ul.nav.nav-pills, ul.nav.nav-tabs").attr("id");
      href = $(e.target).attr('href');
      json[parentId] = href;

      return localStorage.setItem("tabs-state", JSON.stringify(json));
    });

    tabsState = localStorage.getItem("tabs-state");
    json = JSON.parse(tabsState || "{}");

    $.each(json, function(containerId, href) {
      return $("#" + containerId + " a[href=" + href + "]").tab('show');
    });

    $("ul.nav.nav-pills, ul.nav.nav-tabs").each(function() {
      var $this = $(this);
      if (!json[$this.attr("id")]) {
        return $this.find("a[data-toggle=tab]:first, a[data-toggle=pill]:first").tab("show");
      }
    });
  });
};


// Generate jsTree
// @author: Christian Schramm
var makeJsTreeView = function() {
  $('#jstree-default').jstree({
      'plugins': [ "html_data", "checkbox", "wholerow", "types", "ui", "search", "state"],
      "core": {
          "dblclick_toggle": true,
          "themes": {
              "responsive": true,
          }
      },
      "checkbox": {
          "cascade": "",
          "three_state": false,
          "whole_node" : false,
          "tie_selection" : false,
          "real_checkboxes": true
      },
      "state" : { "filter" : function (k) { delete k.core.selected; return k; } },
      "types": {
          "cm":{
            "icon": "fa fa-hdd-o text-warning fa-lg"
          },
          "mta": {
            "icon": "fa fa-fax text-info fa-lg"
          },
          "default": {
              "icon": "fa fa-file-code-o text-success fa-lg"
          }
      }
  });


  $('#jstree-default').on('select_node.jstree', function(e,data) {
      var link = data.node.a_attr.href;
      if (link != "#" && link != "javascript:;" && link != "") {
          document.location.href = link;
          return false;
      }
  });


// trigger on Checkbox change and give
// invisible form the name of selected id
// @author: Christian Schramm

  $('#jstree-default').on("check_node.jstree uncheck_node.jstree", function (e, data) {
      if (data.node.state.checked) {
        document.getElementById('myField'+ data.node.id).name = data.node.id;
      } else {
        document.getElementById('myField'+ data.node.id).name = '';
      }
  });
};

// Select2 Init - intelligent HTML select
// Resize on Zoom to look always pretty
// @author: Christian Schramm
var makeInputFitOnResize = function() {
  $(window).resize(function() {
  $('.select2').css('width', "100%");
  });
  $("select").select2();
};

var positionErdPopover= function {
  $('.erd-popover').hover(
  function(event){
    console.log(event.pageY);
     $(".popover").css({
      top: event.pageY + 5,
      left: event.pageX + 5
  }).show();
});
};

/*
 * Table on-hover click
 * NOTE: This automatically adds on-hover click to all table 'td' elements which are in class 'ClickableTd'.
 *       Please note that the table needs to be in class 'table-hover' for visual marking.
 *
 * HOWTO:
 *  - If clicked on td element which is assigned in class ClickableTd the function bellow is called.
 *  - fetch parent element of td element, which should/(must?) be a row.
 *  - search in tr HTML code for an HTML "a" element and fetch the href attribute
 * INFO: - working directly with row element also adds a click object to checkbox entry, which disabled checkbox functionality
 */
$('.ClickableTd').click(function () {
  window.location = $(this.parentNode).find('a').attr("href");
});


var NMS = function () {
	"use strict";

	return {
		//main function
		init: function () {
			makeNavbarSearch();
			makeInputFitOnResize();
			saveTabPillState();
			makeJsTreeView();
      positionErdPopover();
		},
  };
}();
