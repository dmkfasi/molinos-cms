$(document).ready(function () {
  $('.files u').click(function () {
    var p = $(this).parent().parent();
    var c = $(this).attr('class');

    $('.tab', p).css('display', 'none');
    $('.tab.'+ c, p).css('display', 'block');

    $('.filetabs u span', p).attr('class', 'passive');
    $('.filetabs u.'+ c +' span', p).attr('class', 'active');
  });
});
