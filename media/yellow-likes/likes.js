(function () {
    "use strict";

    var endpoint = "/yellow-likes/";

    function request(action, page) {
        return fetch(endpoint, {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ action: action, page: page })
        }).then(function (response) {
            if (!response.ok) throw new Error("Likes request failed");
            return response.json();
        });
    }

    document.querySelectorAll("[data-yellow-likes]").forEach(function (widget) {
        var button = widget.querySelector(".yellow-likes__button");
        var count = widget.querySelector(".yellow-likes__count");
        var page = window.location.pathname;
        if (!button || !count) return;

        function render(data) {
            count.textContent = String(Number(data.count) || 0);
            button.classList.toggle("is-liked", !!data.liked);
            button.setAttribute("aria-pressed", data.liked ? "true" : "false");
            button.setAttribute("aria-label", data.liked ? "Вы уже поставили лайк" : "Поставить лайк");
        }

        request("status", page).then(render).catch(function () {
            widget.classList.add("is-unavailable");
        });

        button.addEventListener("click", function () {
            if (button.disabled || button.classList.contains("is-liked")) return;
            button.disabled = true;
            request("like", page).then(render).catch(function () {
                widget.classList.add("is-unavailable");
            }).finally(function () {
                button.disabled = false;
            });
        });
    });
}());
