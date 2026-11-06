import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Camera, LoaderCircle, RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

/**
 * How a "Сфотографировать" button should behave on this device:
 *
 * - `native` — a phone, where the file input's `capture` hands over to the
 *   camera app, which is nicer than anything we can draw;
 * - `dialog` — a laptop with a webcam, where `capture` is ignored and we have
 *   to open the camera ourselves;
 * - `none` — no camera to reach, so the button is not shown at all.
 */
export type CameraMode = 'none' | 'native' | 'dialog';

const onAPhone = () =>
    (navigator as Navigator & { userAgentData?: { mobile?: boolean } }).userAgentData?.mobile ??
    /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);

/** Tells a photo button what it can do here; re-checks when a camera is plugged in. */
export function useCameraMode(): CameraMode {
    const [mode, setMode] = useState<CameraMode>('none');

    useEffect(() => {
        let alive = true;

        const look = async () => {
            if (onAPhone()) {
                if (alive) setMode('native');

                return;
            }

            // getUserMedia exists only in a secure context. Over plain http the
            // API is missing altogether, so we cannot tell whether there is a
            // camera; keep the button and let the window explain, rather than
            // have it vanish without a word.
            if (!navigator.mediaDevices?.getUserMedia) {
                if (alive) setMode(window.isSecureContext ? 'none' : 'dialog');

                return;
            }

            try {
                const devices = await navigator.mediaDevices.enumerateDevices();

                if (alive) setMode(devices.some((device) => device.kind === 'videoinput') ? 'dialog' : 'none');
            } catch {
                if (alive) setMode('none');
            }
        };

        look();
        navigator.mediaDevices?.addEventListener('devicechange', look);

        return () => {
            alive = false;
            navigator.mediaDevices?.removeEventListener('devicechange', look);
        };
    }, []);

    return mode;
}

/** Why the camera stayed dark, in words the person can act on. */
function reason(problem: unknown): string {
    if (!window.isSecureContext) return 'Камера открывается только по https или на localhost, а сайт сейчас работает по http.';

    switch (problem instanceof DOMException ? problem.name : '') {
        case 'NotAllowedError':
            return 'Браузер не пустил к камере. Разрешите доступ в настройках сайта и откройте окно снова.';
        case 'NotFoundError':
            return 'Камера не найдена.';
        case 'NotReadableError':
            return 'Камера занята другой программой.';
        default:
            return 'Не удалось включить камеру.';
    }
}

/**
 * A live preview with a shutter. Every shot is handed over as a JPEG file at
 * once, so several can be taken without closing the window.
 */
export function CameraDialog({ onShot, onClose }: { onShot: (photo: File) => void; onClose: () => void }) {
    const view = useRef<HTMLVideoElement>(null);
    const live = useRef<MediaStream | null>(null);
    const [cameras, setCameras] = useState<MediaDeviceInfo[]>([]);
    const [chosen, setChosen] = useState<string>();
    const [error, setError] = useState<string>();
    const [ready, setReady] = useState(false);
    const [taken, setTaken] = useState(0);

    useEffect(() => {
        let alive = true;

        const start = async () => {
            setReady(false);

            try {
                // The back camera where there is a choice; a laptop has the one.
                const stream = await navigator.mediaDevices.getUserMedia({
                    video: chosen ? { deviceId: { exact: chosen } } : { facingMode: 'environment' },
                    audio: false,
                });

                if (!alive) {
                    stream.getTracks().forEach((track) => track.stop());

                    return;
                }

                live.current = stream;
                if (view.current) view.current.srcObject = stream;
                setError(undefined);
                setReady(true);

                // Cameras are named only once permission is given, so the list
                // is worth reading after the first stream and not before.
                const devices = await navigator.mediaDevices.enumerateDevices();

                if (alive) setCameras(devices.filter((device) => device.kind === 'videoinput'));
            } catch (problem) {
                if (alive) setError(reason(problem));
            }
        };

        start();

        return () => {
            alive = false;
            live.current?.getTracks().forEach((track) => track.stop());
            live.current = null;
        };
    }, [chosen]);

    const shoot = () => {
        const source = view.current;

        if (!source) return;

        const frame = document.createElement('canvas');
        frame.width = source.videoWidth;
        frame.height = source.videoHeight;
        frame.getContext('2d')?.drawImage(source, 0, 0);

        frame.toBlob(
            (blob) => {
                if (!blob) return;

                onShot(new File([blob], `photo-${Date.now()}.jpg`, { type: 'image/jpeg' }));
                setTaken((count) => count + 1);
            },
            'image/jpeg',
            0.92,
        );
    };

    const another = () => {
        const at = Math.max(
            0,
            cameras.findIndex((camera) => camera.deviceId === chosen),
        );

        setChosen(cameras[(at + 1) % cameras.length]?.deviceId);
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Снимок</DialogTitle>
                    <DialogDescription>Наведите камеру на оборудование и нажмите «Снять».</DialogDescription>
                </DialogHeader>

                <div className="bg-muted relative aspect-video overflow-hidden rounded-lg border">
                    <video ref={view} autoPlay playsInline muted className="size-full object-cover" />

                    {!ready && !error && (
                        <div className="text-muted-foreground absolute inset-0 grid place-items-center">
                            <LoaderCircle className="size-5 animate-spin" />
                        </div>
                    )}

                    {error && (
                        <p className="absolute inset-0 grid place-items-center p-6 text-center text-sm text-red-600 dark:text-red-400">{error}</p>
                    )}
                </div>

                {taken > 0 && <p className="text-muted-foreground text-[13px]">Снято: {taken}. Снимки уже в форме — можно сделать ещё.</p>}

                <DialogFooter className="sm:justify-between">
                    {cameras.length > 1 ? (
                        <Button type="button" variant="outline" onClick={another}>
                            <RefreshCw />
                            Другая камера
                        </Button>
                    ) : (
                        <span className="hidden sm:block" />
                    )}

                    <div className="grid grid-cols-2 gap-2 sm:flex">
                        <Button type="button" variant="outline" onClick={onClose}>
                            {taken > 0 ? 'Готово' : 'Отмена'}
                        </Button>
                        <Button type="button" onClick={shoot} disabled={!ready}>
                            <Camera />
                            Снять
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
