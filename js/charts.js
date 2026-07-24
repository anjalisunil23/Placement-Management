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
  /**
   * Clustered dashboard bars: blue + orange adjacent, gaps between departments.
   * Designed for a white panel background.
   */
  groupedBar(ctx, labels, series) {
    const CLUSTER = ['#4C8DFF', '#FF9F43', '#A5A5A5', '#FFC000', '#5B9BD5'];
    const text = '#475569';
    const muted = '#64748b';
    const grid = 'rgba(15,23,42,.08)';
    const datasets = (series || []).map((s, i) => ({
      label: s.label || `Series ${i + 1}`,
      data: s.data || [],
      backgroundColor: s.color || CLUSTER[i % CLUSTER.length],
      borderWidth: 0,
      borderRadius: { topLeft: 6, topRight: 6, bottomLeft: 0, bottomRight: 0 },
      borderSkipped: false,
      maxBarThickness: 34,
      barPercentage: 1,
      categoryPercentage: 0.42,
    }));
    return this._mount(ctx, {
      type: 'bar',
      data: { labels, datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        datasets: {
          bar: {
            barPercentage: 1,
            categoryPercentage: 0.42,
          },
        },
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              color: muted,
              boxWidth: 10,
              boxHeight: 10,
              usePointStyle: false,
              padding: 18,
              font: { family: 'Inter', size: 12, weight: '500' },
            },
          },
          tooltip: {
            mode: 'index',
            intersect: false,
            backgroundColor: 'rgba(15,23,42,.92)',
            titleColor: '#fff',
            bodyColor: '#e2e8f0',
            borderWidth: 0,
            padding: 10,
          },
        },
        scales: {
          x: {
            stacked: false,
            ticks: {
              color: muted,
              maxRotation: 45,
              minRotation: 0,
              font: { family: 'Inter', size: 11 },
            },
            grid: {
              color: grid,
              borderDash: [4, 4],
              drawBorder: false,
            },
            border: { display: false },
          },
          y: {
            stacked: false,
            beginAtZero: true,
            ticks: {
              color: muted,
              precision: 0,
              font: { family: 'Inter', size: 11 },
            },
            grid: {
              color: grid,
              borderDash: [4, 4],
              drawBorder: false,
            },
            border: { display: false },
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
