/*window_resize - begin*/
var d = document;
var winIE = (navigator.userAgent.indexOf("Opera")==-1 && (d.getElementById &&  d.documentElement.behaviorUrns))  ? true : false;

function bodySize(){
	if(winIE && d.documentElement.clientWidth) {
		sObj = d.getElementsByTagName("body")[0].style;
		sObj.width = (d.documentElement.clientWidth<1000) ? "1000px" : "100%";
	}
}

function init(){
	if(winIE) { bodySize(); }
}
 
onload = init;

if(winIE) { onresize = bodySize; }
/*window_resize - end*/


$(document).ready(function () {
	$("input:checked").parent().parent().addClass("current");

	$(":checkbox").change(function () {
		$(this).parent().parent().toggleClass("current");
	});
	
	$("input[name='select_all']").change(function () {
		$(this).parent().parent().parent().find("tr").toggleClass("current");
	});

	$(".as_link").click(function() {
    $('.jqmw', $(this).parent()).jqm().jqmShow();

    // $('.as_actions', $(this).parent()).toggleClass('hidden');
		return(false);
	});

  $('form.node-domain-create-form input.form-radio').change(function () {
    if ($(this).attr('value') == 'domain') {
      $('#domain-aliases-wrapper').removeClass('hidden');
      $('#domain-parent-wrapper').addClass('hidden');
    } else {
      $('#domain-aliases-wrapper').addClass('hidden');
      $('#domain-parent-wrapper').removeClass('hidden');
    }
  });
  $('#domain-parent-wrapper').addClass('hidden');

  // Скрываем выбор раздела по умолчанию.
  $('form.node-domain-edit-form #control-node-params-wrapper select').change(bebop_fix_domain_defaultsection);
  bebop_fix_domain_defaultsection();

  bebop_fix_files();
});

function bebop_fix_files()
{
  $('#center :file').each(function (i) {
    var f = $('#center :file').eq(i);
    var id = f.attr('id').replace('-input', '');

    var html = "<a id='"+ id +"-del-link' href='javascript:mcms_file_delete(\""+ id +"\");'>удалить</a> "
      +"<a href='javascript:mcms_file_pick(\""+ id +"\");'>подобрать</a>";

    f.after('<p>'+ html +'</p>');

    var current = $('#center :hidden#'+ id +'-hidden').attr('value');
    if (current)
      f.before("<a href='/attachment/"+ current +"' target='_blank'><img id='"+ id +"-preview' src='/attachment/"+ current +",100,100,d' alt='preview' style='margin: 0 4px 4px 0; float: left;' /></a>");

    $('#center #'+ id +'-input').parent().after("<div style='clear: both;'></div>");
  });
}

function bebop_fix_domain_defaultsection()
{
  switch ($('form.node-domain-edit-form #control-node-params-wrapper select').attr('value')) {
  case 'sec':
  case 'sec+doc':
    $('form.node-domain-edit-form #control-node-defaultsection-wrapper').removeClass('hidden');
    break;
  default:
    $('form.node-domain-edit-form #control-node-defaultsection-wrapper').addClass('hidden');
  }
}

function mcms_file_delete(id)
{
  var hval = '';

  if ($('#center #'+ id +'-del-link').toggleClass('bold').hasClass('bold'))
    hval = 'deleted';

  $('#center #'+ id +'-hidden').attr('value', hval);
}

function mcms_file_pick(id)
{
  window.open('/admin/files/picker/?BebopFiles.picker='+ id, '_blank');
}

function mcms_picker_return(href)
{
  var fileid = href.replace('/attachment/', '');

  if (mcms_picker_id == 'src') {
    if (tinyMCE) {
      var tmp_win = tinyMCE.getWindowArg('window');
      if (tmp_win) {
        tmp_win.document.getElementById('src').value = href;
      }
    }
  } else {
    // Заменяем старый предпросмотр новым.
    window.opener.jQuery('#'+ mcms_picker_id +'-preview').remove();
    window.opener.jQuery('#'+ mcms_picker_id +'-input').before("<img id='"+ mcms_picker_id +"-preview' src='/attachment/"+ fileid +",100,100,d' alt='preview' style='margin: 0 4px 4px 0; float: left;' />");

    // Заменяем скрытое значение.
    window.opener.jQuery('#'+ mcms_picker_id +'-hidden').attr('value', fileid);

    // Сбрасываем отметку об удалении.
    window.opener.jQuery('#center #'+ mcms_picker_id +'-del-link').removeClass('bold');
  }

  window.close();
  return false;
}

function bebop_select(table, mode)
{
  switch (mode) {
  case 'all':
    $('#'+ table +' tr.data input[type="checkbox"]').attr('checked', 'checked');
    $('#'+ table +' tr.data').addClass('current');
    break;

  case 'none':
    $('#'+ table +' tr.data input[type="checkbox"]').attr('checked', '');
    $('#'+ table +' tr.data').removeClass('current');
    break;

  case 'published':
    $('#'+ table +' tr.data.unpublished input[type="checkbox"]').attr('checked', '');
    $('#'+ table +' tr.data.unpublished').removeClass('current');
    $('#'+ table +' tr.data.published input[type="checkbox"]').attr('checked', 'checked');
    $('#'+ table +' tr.data.published').addClass('current');
    break;

  case 'unpublished':
    $('#'+ table +' tr.data.published input[type="checkbox"]').attr('checked', '');
    $('#'+ table +' tr.data.published').removeClass('current');
    $('#'+ table +' tr.data.unpublished input[type="checkbox"]').attr('checked', 'checked');
    $('#'+ table +' tr.data.unpublished').addClass('current');
    break;
  }
}

function bebop_content_action(name, title)
{
  if (!confirm('Вы уверены, что хотите '+ title +' выделенные объекты?'))
    return;

  var url = $('#contentForm').attr('action');
  $('#contentForm').attr('action', '/admin/node/'+ name +'/'+ url);
  $('#contentForm').submit();
}