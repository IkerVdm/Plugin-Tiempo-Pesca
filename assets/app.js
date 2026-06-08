(function () {
  function formatNumber(value, digits, suffix) {
    if (value === null || value === undefined || value === "") return "-";
    return `${Number(value).toFixed(digits)}${suffix || ""}`;
  }

  function formatTime(value) {
    if (!value) return "-";
    return new Date(value).toLocaleTimeString("es-ES", { hour: "2-digit", minute: "2-digit" });
  }

  function formatDate(value) {
    if (!value) return "-";
    return new Date(value).toLocaleString("es-ES", {
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

  function renderTideTurn(turn) {
    if (!turn) return "-";
    return `${turn.kind} ${formatDate(turn.time)} · ${formatNumber(turn.height, 2, " m")}`;
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
          <div><strong>${formatNumber(window.confidence, 0, "%")}</strong><span>Acierto</span></div>
          <div><strong>${formatNumber(window.fishingScore, 0, "/100")}</strong><span>Potencial</span></div>
        </div>
        <div class="donpesca-tags">
          <span class="donpesca-chip">Viento ${window.windDirectionLabel || "-"} ${formatNumber(window.windDirection, 0, "°")}</span>
          <span class="donpesca-chip">Mar ${window.waveDirectionLabel || "-"} ${formatNumber(window.waveDirection, 0, "°")}</span>
          <span class="donpesca-chip">Marea ${window.tideState}</span>
          <span class="donpesca-chip">Coeficiente ${window.coefficientType}</span>
          ${renderModelChips(window.models.wind, " kn")}
        </div>
        <p class="donpesca-copy">${window.directionImpact || ""}</p>
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

  function renderReport(payload) {
    const best = payload.bestWindow;
    const bestDaySlot = payload.bestDaySlot;
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
              <span>Potencial del momento</span>
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
          <h3>Franja más adecuada del día</h3>
          <dl class="donpesca-list">
            <div><dt>Franja</dt><dd>${bestDaySlot ? bestDaySlot.label : best.timeLabel}</dd></div>
            <div><dt>Prob. acierto</dt><dd>${formatNumber(bestDaySlot ? bestDaySlot.confidence : best.confidence, 0, "%")}</dd></div>
            <div><dt>Potencial</dt><dd>${formatNumber(bestDaySlot ? bestDaySlot.fishingScore : best.fishingScore, 0, "/100")}</dd></div>
            <div><dt>Marea</dt><dd>${bestDaySlot ? bestDaySlot.tideState : best.tideState}</dd></div>
            <div><dt>Coeficiente</dt><dd>${bestDaySlot ? bestDaySlot.coefficientType : best.coefficientType}</dd></div>
            <div><dt>Viento</dt><dd>${formatNumber(bestDaySlot ? bestDaySlot.windWorst : best.windWorst, 1, " kn")}</dd></div>
            <div><dt>Dir. viento</dt><dd>${bestDaySlot ? (bestDaySlot.windDirectionLabel || "-") : (best.windDirectionLabel || "-")} · ${formatNumber(bestDaySlot ? bestDaySlot.windDirection : best.windDirection, 0, "°")}</dd></div>
            <div><dt>Rachas</dt><dd>${formatNumber(bestDaySlot ? bestDaySlot.gustWorst : best.gustWorst, 1, " kn")}</dd></div>
            <div><dt>Mar</dt><dd>${formatNumber(bestDaySlot ? bestDaySlot.waveHeight : best.waveHeight, 2, " m")} · ${formatNumber(bestDaySlot ? bestDaySlot.wavePeriod : best.wavePeriod, 1, " s")}</dd></div>
            <div><dt>Dir. ola</dt><dd>${bestDaySlot ? (bestDaySlot.waveDirectionLabel || "-") : (best.waveDirectionLabel || "-")} · ${formatNumber(bestDaySlot ? bestDaySlot.waveDirection : best.waveDirection, 0, "°")}</dd></div>
            <div><dt>Marea anterior</dt><dd>${renderTideTurn(bestDaySlot ? bestDaySlot.tidePrevious : best.tidePrevious)}</dd></div>
            <div><dt>Marea siguiente</dt><dd>${renderTideTurn(bestDaySlot ? bestDaySlot.tideNext : best.tideNext)}</dd></div>
          </dl>
        </article>
      </section>

      <section class="donpesca-grid donpesca-grid--three">
        <article class="donpesca-card">
          <h3>Mejor franja del día</h3>
          <p><strong>${bestDaySlot ? bestDaySlot.label : best.timeLabel}</strong></p>
          <p>${bestDaySlot ? bestDaySlot.reason : "Es la ventana con mejor equilibrio del día."}</p>
          <p>${bestDaySlot ? bestDaySlot.reasonDetail : best.reason}</p>
          <p>${bestDaySlot ? bestDaySlot.directionImpact : best.directionImpact}</p>
          <p>${fishingFit.reason}</p>
        </article>
        <article class="donpesca-card">
          <h3>Mareas</h3>
          <p><strong>Nivel estimado:</strong> ${formatNumber(payload.tides.currentLevel, 2, " m")}</p>
          <p><strong>Último cambio:</strong> ${renderTideTurn(payload.tides.previousTurn)}</p>
          <p><strong>Siguiente cambio:</strong> ${renderTideTurn(payload.tides.nextTurn)}</p>
          <p>${payload.tides.disclaimer}</p>
        </article>
        <article class="donpesca-card">
          <h3>Modelos y lectura</h3>
          <p>${payload.consensus.confidenceFormula}</p>
          <p>${payload.notes[0]}</p>
          <p>${payload.notes[1]}</p>
        </article>
      </section>

      <section class="donpesca-section">
        <div class="donpesca-section__head">
          <h3>Ventanas recomendadas</h3>
          <p>Ordenadas por equilibrio de condiciones y después por confianza del parte.</p>
        </div>
        <div class="donpesca-grid donpesca-grid--windows">
          ${payload.windows.map(renderWindow).join("")}
        </div>
      </section>

      <section class="donpesca-section">
        <div class="donpesca-section__head">
          <h3>Sol, luna y actividad</h3>
          <p>La fase lunar y los cambios de luz añaden contexto a la franja destacada.</p>
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

    if (!form || !results || !status || typeof DonPescaMarForecast === "undefined") return;

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
          throw new Error((json && json.data && json.data.message) || DonPescaMarForecast.strings.error);
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
