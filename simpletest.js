// $Id$

$(document).ready(function() {
	// Adds expand-collapse functionality.
	$('img.simpletest-menu-collapse').click(function(){
		if($(this).data('collapsed') == 1){
			$(this).data('collapsed', 0);
			this.src = '/misc/menu-expanded.png';
		}else{
			$(this).data('collapsed',1);
			this.src = '/misc/menu-collapsed.png';
		}
		// Toggle all of the trs.
		$("tr."+this.id.replace(/\-menu\-collapse/,'')+"-test").toggle();
	});

  $('.select-all').each(function() {
  	var checkbox = $('<input type="checkbox" id="'+ this.id +'" />');
    $('#'+ this.id).html(checkbox);

    var checkboxes = $('.'+ this.id +'-test');
    var selectAllChecked = 1;
    var collapsed = 1;
    for (var i = 0; i < checkboxes.length; i++) {
      if (!checkboxes[i].checked) {
        selectAllChecked = 0;
      }
      else {
        collapsed = 0;
      }
    }
    
    // Finds all checkboxes for particular test group and sets them to the "check all" state.
    checkbox.attr('checked', selectAllChecked).click(function() {
      var rows = $('.'+ this.id +'-test');
      for (var i = 0; i < rows.length; i++) {
        $(rows[i]).find(':checkbox').attr('checked', this.checked);
      }
    }).end();
  });
  
  // Set the initial state of the expand-collapse. 
	$('img.simpletest-menu-collapse').each(function(){
		// Only set the state if it has not been set previously.
		if($(this).data('collapsed') == undefined){
			$(this).data('collapsed',1);
			// See if any of the child checkboxes are checked and expand the menu if they are.
			var doCheck = false;
			$("tr."+this.id.replace(/\-menu\-collapse/,'')+"-test").find("input").each(function(){
				if(this.checked){
					doCheck = true;
				}
			})
			if(doCheck){
				$(this).click()
			}
		}
	});
});
