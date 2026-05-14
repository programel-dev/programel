import Link from "next/link";

const tools = [
  {
    href: "/equeue",
    title: "e-queue моніторинг",
    description:
      "Відстежуй вільні слоти консульства України в Мюнхені. Отримуй сповіщення в Telegram щойно з'явиться місце.",
  },
];

export default function HomePage() {
  return (
    <main className="mx-auto w-full max-w-3xl space-y-10 p-4 sm:p-8">
      <section>
        <h2 className="mb-4 text-xs font-semibold uppercase tracking-widest text-zinc-400">
          Інструменти
        </h2>
        <ul className="space-y-3">
          {tools.map((tool) => (
            <li key={tool.href}>
              <Link
                href={tool.href}
                className="flex flex-col gap-1 rounded-xl border border-zinc-200 p-5 transition-colors hover:border-zinc-400 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:border-zinc-600 dark:hover:bg-zinc-900"
              >
                <span className="font-semibold text-zinc-900 dark:text-zinc-50">
                  {tool.title}
                </span>
                <span className="text-sm text-zinc-500 dark:text-zinc-400">
                  {tool.description}
                </span>
              </Link>
            </li>
          ))}
        </ul>
      </section>
    </main>
  );
}
