import { TelegramConnectButton } from "./TelegramConnectButton";
import { WatchList } from "./WatchList";

export const metadata = {
  title: "e-queue моніторинг",
};

export default function EqueuePage() {
  return (
    <main className="mx-auto w-full max-w-3xl space-y-8 p-8">
      <header className="space-y-2">
        <h1 className="text-3xl font-bold">e-queue моніторинг</h1>
        <p className="text-sm text-zinc-600 dark:text-zinc-400">
          Підпишись на вільні слоти консульства України в Мюнхені
          (munich.pasport.org.ua). Ми перевіряємо сторінку кожні 5 хвилин і
          надсилаємо повідомлення в Telegram, щойно з&apos;явиться слот.
        </p>
      </header>

      <section>
        <h2 className="mb-3 text-xl font-semibold">Канал сповіщень</h2>
        <TelegramConnectButton />
      </section>

      <section>
        <h2 className="mb-3 text-xl font-semibold">Відстеження</h2>
        <WatchList />
      </section>
    </main>
  );
}
