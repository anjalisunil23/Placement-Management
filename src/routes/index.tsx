import { createFileRoute } from "@tanstack/react-router";

export const Route = createFileRoute("/")({
  head: () => ({
    meta: [
      { title: "Your App" },
      { name: "description", content: "Replace this with a one-sentence description of your app." },
      { property: "og:title", content: "Your App" },
      { property: "og:description", content: "Replace this with a one-sentence description of your app." },
    ],
  }),
  component: Index,
});

// IMPORTANT: Replace this placeholder. See ./README.md for routing conventions.
function Index() {
  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-50 p-6">
      <div className="max-w-2xl text-center">
        <h1 className="text-4xl font-bold tracking-tight">PlaceHub — Placement Drive Management</h1>
        <p className="mt-3 text-slate-600">
          A complete static Bootstrap 5.3 + Chart.js UI for placement administration.
        </p>
        <div className="mt-6 flex flex-wrap justify-center gap-3">
          <a href="/placement/index.html" className="rounded-lg bg-indigo-600 px-5 py-2.5 font-semibold text-white hover:bg-indigo-700">
            Open Login →
          </a>
          <a href="/placement/dashboard.html" className="rounded-lg border border-slate-300 bg-white px-5 py-2.5 font-semibold text-slate-700 hover:bg-slate-100">
            Open Dashboard
          </a>
          <a href="/placement/public-stats.html" className="rounded-lg border border-slate-300 bg-white px-5 py-2.5 font-semibold text-slate-700 hover:bg-slate-100">
            Public Portal
          </a>
        </div>
        <p className="mt-6 text-sm text-slate-500">
          Pages: Login · Dashboard · Drives · Create Drive · Students · Eligibility · Company Portal · Applicants · Tracking · Reports · Analytics · Notifications · Public Stats · Settings
        </p>
      </div>
    </div>
  );
}
