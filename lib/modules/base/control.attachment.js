// Подбор файлов для Molinos.CMS.  Используется при редактировании
// объектов, для выбора существующего файла их архива (открывает
// всплывающее окно).

var mcms_picker = {
  mySubmit: function (URL, id) {
    alert('Не работает пока ;(');
    return false;
  },

  open: function (arg) {
    url = mcms_path + '/admin?mode=list&preset=files&cgroup=content&picker='+ field_name;
    if (win)
      url += '&window='+ win.name;
    window.open(url, '_blank');
  }
};

// Исключение нужно обрабатывать потому, что этот же скрипт
// грузится на странице выбора файла, но jQuery там нет.
try {
  $(document).ready(function () {
    $('#center .form-file.archive').each(function (i) {
      var f = $('#center .form-file.archive').eq(i);
      var id = f.attr('id').replace('-input', '');

      var html = ""
        +"<label style='display:inline'><input type='checkbox' value='1' id='"+id+"-delete' name='"+$('#'+id+'-input').attr('name')+"[delete]' /> удалить</label>"
        +" или <a href='javascript:mcms_picker.open(\""+ id +"\");'>подобрать</a>"
        ;

      f.after("<p class='attctl'>"+ html +'</p>');

      var current = $('#center :hidden#'+ id +'-hidden').attr('value');
      if (current)
        f.before("<img id='"+ id +"-preview' src='/attachment/"+ current +",100,100,d' alt='preview' style='margin: 0 4px 4px 0; float: left;' />");

      $('#center #'+ id +'-input').parent().after("<div style='clear: both;'></div>");
    });
  });
} catch (error) {
}