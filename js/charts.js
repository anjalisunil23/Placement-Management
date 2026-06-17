/* Chart helpers — uses Chart.js loaded via CDN */
function themeColors() {
  const dark = document.documentElement.getAttribute("data-theme") === "dark";
  return {
    grid: dark ? "rgba(255,255,255,.08)" : "rgba(15,23,42,.08)",
    text: dark ? "#cbd5e1" : "#475569",
  };
}

function baseOpts() {
  const c = themeColors();
  return {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { labels: { color: c.text, font: { family: "Inter" } } } },
    scales: {
      x: { ticks: { color: c.text }, grid: { color: c.grid } },
      y: { ticks: { color: c.text }, grid: { color: c.grid } },
    },
  };
}

const PALETTE = ["#5b5bd6", "#7c5cff", "#ec4899", "#f59e0b", "#16a34a", "#0ea5e9", "#ef4444", "#14b8a6"];

const Charts = {
  bar(ctx, labels, data, label = "Value") {
    return new Chart(ctx, {
      type: "bar",
      data: {
        labels,
        datasets: [{
          label, data,
          backgroundColor: labels.map((_, i) => PALETTE[i % PALETTE.length]),
          borderRadius: 8, borderSkipped: false, maxBarThickness: 38,
        }],
      },
      options: baseOpts(),
    });
  },
  line(ctx, labels, datasets) {
    return new Chart(ctx, {
      type: "line",
      data: {
        labels,
        datasets: datasets.map((d, i) => ({
          ...d,
          borderColor: PALETTE[i % PALETTE.length],
          backgroundColor: PALETTE[i % PALETTE.length] + "33",
          tension: .35, fill: true, pointRadius: 3, borderWidth: 2,
        })),
      },
      options: baseOpts(),
    });
  },
  pie(ctx, labels, data) {
    return new Chart(ctx, {
      type: "pie",
      data: { labels, datasets: [{ data, backgroundColor: PALETTE }] },
      options: { ...baseOpts(), scales: {} },
    });
  },
  doughnut(ctx, labels, data) {
    return new Chart(ctx, {
      type: "doughnut",
      data: { labels, datasets: [{ data, backgroundColor: PALETTE, borderWidth: 0 }] },
      options: { ...baseOpts(), cutout: "70%", scales: {} },
    });
  },
};
