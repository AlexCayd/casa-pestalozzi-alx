/* Horario — resalta el día actual */
function initHours() {
  var today = new Date().getDay();
  var row = $(".reserva__hours .row[data-day=\"" + today + "\"]");
  if (row) {
    row.classList.add("today");
  }
}
