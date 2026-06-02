/* Menú — tabs, lista de platos y preview */
var currentCat = 0;

function initMenu() {
  var data = window.CP_MENU || [];
  var tabs = $("#menuTabs"), list = $("#menuList");
  var pcCat = $("#pcCat"), pcName = $("#pcName"), pcCount = $("#pcCount"), frame = $("#previewFrame");

  data.forEach(function(cat, i) {
    var b = document.createElement("button");
    b.className = "menu__tab" + (i === 0 ? " active" : "");
    b.innerHTML = cat.label + '<span class="count">' + String(cat.dishes.length).padStart(2, "0") + '</span>';
    b.addEventListener("click", function() { selectCat(i); });
    tabs.appendChild(b);
  });

  var imgCache = {};

  function preview(cat, dish) {
    pcCat.textContent = cat.label;
    pcName.textContent = dish ? dish.n : cat.kicker;
    if (!dish) return;
    var img = imgCache[dish.img];
    if (!img) {
      img = document.createElement("img");
      img.src = dish.img; img.alt = dish.n;
      frame.insertBefore(img, frame.firstChild);
      imgCache[dish.img] = img;
    }
    $$(".menu__preview-frame img").forEach(function(im) { im.classList.remove("show"); });
    img.classList.add("show");
    frame.setAttribute("data-zoom-src", dish.img);
    frame.setAttribute("data-zoom-name", dish.n);
    frame.setAttribute("data-zoom-cat", cat.label);
  }

  function renderList(catIndex) {
    var cat = data[catIndex];
    list.innerHTML = "";
    pcCount.textContent = cat.dishes.length + " platillos";
    cat.dishes.forEach(function(d, j) {
      var row = document.createElement("div");
      row.className = "dish";
      var tags = (d.tags || []).map(function(t) { return '<span class="dish__tag">' + t + '</span>'; }).join("");
      row.innerHTML =
        '<div class="dish__thumb" data-zoom data-zoom-cat="' + cat.label + '"><img src="' + d.img + '" alt="' + d.n + '" loading="lazy" /></div>' +
        '<span class="dish__idx">' + String(j + 1).padStart(2, "0") + '</span>' +
        '<div class="dish__main"><h4>' + d.n + '</h4>' +
        (d.d ? '<p>' + d.d + '</p>' : '') +
        (tags ? '<div class="dish__tags">' + tags + '</div>' : '') +
        '</div>' +
        '<span class="dish__price">$' + d.p + '<small> mxn</small></span>';
      row.addEventListener("mouseenter", function() {
        $$(".dish").forEach(function(x) { x.classList.remove("active"); });
        row.classList.add("active");
        preview(cat, d);
      });
      list.appendChild(row);
    });
    if (!reduce && window.gsap) gsap.fromTo(list.children, { opacity: 0, y: 24 }, { opacity: 1, y: 0, duration: 0.6, stagger: 0.05, ease: "power3.out" });
    preview(cat, cat.dishes[0]);
    if (list.firstChild) list.firstChild.classList.add("active");
  }

  function selectCat(i) {
    currentCat = i;
    $$(".menu__tab").forEach(function(t, k) { t.classList.toggle("active", k === i); });
    renderList(i);
    if (window.ScrollTrigger) ScrollTrigger.refresh();
  }

  renderList(0);
}
