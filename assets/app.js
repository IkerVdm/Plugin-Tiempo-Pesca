(function () {
  function formatNumber(value, digits, suffix) {
    if (value === null || value === undefined || value === "") {
      return "-";
    }
    return `${Number(value).toFixed(digits)}${suffix || ""}`;
  }

  function formatTime(value) {
    if (!value) return "-";
    const date = new Date(value);
    return date.toLocaleTimeString("es-ES", { hour: "2-digit", minute: "2-digit" });
  }

  function formatDate(value) {
    if (!value) return "-";
    const date = new Date(value);
    return date.toLocaleString("es-ES", {
      weekday: "short",
      day: "2-digit",
      month: "short",
      hour: "2-digit",
      minute: "2-digit",
    });
  }

  function renderModelChips(rows, suffix) {
    if (!rows || !rows.length) return '<span class="donpesca-chip">Sin detalle</span>';
    return rows
      .map((row) => `<span class="donpesca-chip">${row.name}: ${formatNumber(row.value, 1, suffix)}</span>`)
      .join("");
  }

  function renderWindow(window) {
    return `
      <article class="donpesca-card donpesca-card--window donpesca-card--${window.status.toLowerCase()}">
        <div class="donpesca-card__top">
          <div>
            <span class="donpesca-kicker">${window.timeLabel}</span>
            <h4>${window.headline}</h4>
          </div>
          <span class="donpesca-pill donpesca-pill--${window.status.toLowerCase()}">${window.status}</span>
        </div>
        <p class="donpesca-copy">${window.reason}</p>
        <div class="donpesca-metrics">
          <div><strong>${formatNumber(window.windWorst, 1, " kn")}</strong><span>Viento peor</span></div>
          <div><strong>${formatNumber(window.gustWorst, 1, " kn")}</strong><span>Racha peor</span></div>
          <div><strong>${formatNumber(window.waveHeight, 2, " m")}</strong><span>Ola</span></div>
          <div><strong>${formatNumber(window.wavePeriod, 1, " s")}</strong><span>Periodo</span></div>
          <div><strong>${formatNumber(window.confidence, 0, "%")}</strong><span>Confianza</span></div>
          <div><strong>${formatNumber(window.fishingScore, 0, "/100")}</strong><span>Encaje pesca</span></div>
        </div>
        <div class="donpesca-tags">
          ${renderModelChips(window.models.wind, " kn")}
          ${renderModelChips(window.models.gust, " kn")}
        </div>
      </article>
    `;
  }

  function renderAstronomy(days) {
    return days
      .map(
        (day) => `
          <article class="donpesca-card donpesca-card--mini">
            <h4>${day.date}</h4>
            <p><strong>Sol</strong> ${formatTime(day.sunrise)} / ${formatTime(day.sunset)}</p>
            <p><strong>Luna</strong> ${day.moonPhaseLabel || "-"} · ${formatTime(day.moonrise)} / ${formatTime(day.moonset)}</p>
            <p>${day.moonFishingNote || "Sin lectura lunar disponible."}</p>
          </article>
        `
      )
      .join("");
  }

  function renderTideTurn(turn) {
    if (!turn) return "-";
    return `${turn.kind} ${formatDate(turn.time)} · ${formatNumber(turn.height, 2, " m")}`;
  }

  function renderReport(payload) {
    const best = payload.bestWindow;
    const summary = payload.summary;
    const fishingFit = summary.fishingFit;

    return `
      <section class="donpesca-grid donpesca-grid--hero">
        <article class="donpesca-card donpesca-card--feature donpesca-card--${summary.status.toLowerCase()}">
          <div class="donpesca-card__top">
            <div>
              <span class="donpesca-kicker">${payload.location.name} · ${payload.location.region}</span>
              <h3>${summary.headline}</h3>
            </div>
            <span class="donpesca-pill donpesca-pill--${summary.status.toLowerCase()}">${summary.status}</span>
          </div>
          <div class="donpesca-scoreboard">
            <div>
              <strong>${formatNumber(summary.confidence, 0, "%")}</strong>
              <span>Probabilidad de acierto</span>
            </div>
            <div>
              <strong>${formatNumber(fishingFit.score, 0, "/100")}</strong>
              <span>Encaje de pesca</span>
            </div>
            <div>
              <strong>${fishingFit.label}</strong>
              <span>Lectura global</span>
            </div>
          </div>
          <div class="donpesca-summary">
            ${summary.texts.map((text) => `<p>${text}</p>`).join("")}
          </div>
        </article>

        <article class="donpesca-card donpesca-card--snapshot">
          <h3>Mejor ventana detectada</h3>
          <dl class="donpesca-list">
            <div><dt>Hora</dt><dd>${best.timeLabel}</dd></div>
            <div><dt>Viento</dt><dd>${formatNumber(best.windWorst, 1, " kn")} peor escenario</dd></div>
            <div><dt>Rachas</dt><dd>${formatNumber(best.gustWorst, 1, " kn")}</dd></div>
            <div><dt>Mar</dt><dd>${formatNumber(best.waveHeight, 2, " m")} · ${formatNumber(best.wavePeriod, 1, " s")}</dd></div>
            <div><dt>Dirección de mar</dt><dd>${formatNumber(best.waveDirection, 0, "°")}</dd></div>
            <div><dt>Energía</dt><dd>${formatNumber(best.waveEnergy, 1, "")}</dd></div>
            <div><dt>Marea anterior</dt><dd>${renderTideTurn(best.tidePrevious)}</dd></div>
            <div><dt>Marea siguiente</dt><dd>${renderTideTurn(best.tideNext)}</dd></div>
            <div><dt>Luna</dt><dd>${best.moonPhase || "-"}</dd></div>
          </dl>
        </article>
      </section>

      <section class="donpesca-grid donpesca-grid--three">
        <article class="donpesca-card">
          <h3>Lectura operativa</h3>
          <p>${best.reason}</p>
          <p>${fishingFit.reason}</p>
          <div class="donpesca-tags">
            ${renderModelChips(best.models.wind, " kn")}
          </div>
        </article>
        <article class="donpesca-card">
          <h3>Mareas</h3>
          <p><strong>Nivel estimado actual:</strong> ${formatNumber(payload.tides.currentLevel, 2, " m")}</p>
          <p><strong>Último cambio:</strong> ${renderTideTurn(payload.tides.previousTurn)}</p>
          <p><strong>Siguiente cambio:</strong> ${renderTideTurn(payload.tides.nextTurn)}</p>
          <p>${payload.tides.disclaimer}</p>
        </article>
        <article class="donpesca-card">
          <h3>Modelos y criterio</h3>
          <p>${payload.consensus.confidenceFormula}</p>
          <p>${payload.notes[0]}</p>
          <p>${payload.notes[2]}</p>
        </article>
      </section>

      <section class="donpesca-section">
        <div class="donpesca-section__head">
          <h3>Ventanas recomendadas</h3>
          <p>Ordenadas por encaje de pesca y nivel de confianza.</p>
        </div>
        <div class="donpesca-grid donpesca-grid--windows">
          ${payload.windows.map(renderWindow).join("")}
        </div>
      </section>

      <section class="donpesca-section">
        <div class="donpesca-section__head">
          <h3>Sol, luna y actividad</h3>
          <p>La fase lunar y los cambios de luz suman contexto, no sustituyen al mar real.</p>
        </div>
        <div class="donpesca-grid donpesca-grid--astro">
          ${renderAstronomy(payload.astronomy)}
        </div>
      </section>
    `;
  }

  document.addEventListener("DOMContentLoaded", function () {
    const form = document.querySelector("[data-donpesca-form]");
    const results = document.querySelector("[data-donpesca-results]");
    const status = document.querySelector("[data-donpesca-status]");

    if (!form || !results || !status || typeof DonPescaMarForecast === "undefined") {
      return;
    }

    async function submitForm() {
      status.textContent = DonPescaMarForecast.strings.loading;
      results.innerHTML = "";

      const formData = new FormData(form);
      formData.append("action", DonPescaMarForecast.action);
      formData.append("nonce", DonPescaMarForecast.nonce);

      try {
        const response = await fetch(DonPescaMarForecast.ajaxUrl, {
          method: "POST",
          body: formData,
          credentials: "same-origin",
        });

        const json = await response.json();
        if (!response.ok || !json.success) {
          throw new Error(json?.data?.message || DonPescaMarForecast.strings.error);
        }

        status.textContent = `Informe generado para ${json.data.location.name}.`;
        results.innerHTML = renderReport(json.data);
      } catch (error) {
        status.textContent = error.message || DonPescaMarForecast.strings.error;
      }
    }

    form.addEventListener("submit", function (event) {
      event.preventDefault();
      submitForm();
    });

    submitForm();
  });
})();
