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
  _mount(ctx, config) {
    const canvas = typeof ctx === 'string' ? document.getElementById(ctx) : ctx;
    if (!canvas) return null;
    const existing = typeof Chart !== 'undefined' && Chart.getChart ? Chart.getChart(canvas) : null;
    if (existing) existing.destroy();
    return new Chart(canvas, config);
  },
  bar(ctx, labels, data, label = "Value") {
    return this._mount(ctx, {
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
  /** Clustered (grouped) bars — Excel-style comparison, legend at bottom. */
  groupedBar(ctx, labels, series) {
    const CLUSTER = ['#4472C4', '#ED7D31', '#A5A5A5', '#FFC000', '#5B9BD5'];
    const c = themeColors();
    const datasets = (series || []).map((s, i) => ({
      label: s.label || `Series ${i + 1}`,
      data: s.data || [],
      backgroundColor: s.color || CLUSTER[i % CLUSTER.length],
      borderColor: s.color || CLUSTER[i % CLUSTER.length],
      borderWidth: 0,
      borderRadius: 2,
      borderSkipped: false,
      maxBarThickness: 36,
      categoryPercentage: 0.7,
      barPercentage: 0.9,
    }));
    return this._mount(ctx, {
      type: 'bar',
      data: { labels, datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              color: c.text,
              boxWidth: 12,
              boxHeight: 12,
              usePointStyle: false,
              padding: 16,
              font: { family: 'Inter', size: 12 },
            },
          },
          tooltip: { mode: 'index', intersect: false },
        },
        scales: {
          x: {
            stacked: false,
            ticks: { color: c.text, maxRotation: 45, minRotation: 0 },
            grid: { display: false, drawBorder: false },
          },
          y: {
            stacked: false,
            beginAtZero: true,
            ticks: { color: c.text, precision: 0 },
            grid: { color: c.grid, drawBorder: false },
          },
        },
      },
    });
  },
  line(ctx, labels, datasets) {
    return this._mount(ctx, {
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
    return this._mount(ctx, {
      type: "pie",
      data: { labels, datasets: [{ data, backgroundColor: PALETTE }] },
      options: { ...baseOpts(), scales: {} },
    });
  },
  doughnut(ctx, labels, data) {
    return this._mount(ctx, {
      type: "doughnut",
      data: { labels, datasets: [{ data, backgroundColor: PALETTE, borderWidth: 0 }] },
      options: { ...baseOpts(), cutout: "70%", scales: {} },
    });
  },
};
