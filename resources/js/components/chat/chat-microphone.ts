type MicrophoneUse = 'call' | 'call_check' | 'voice_note';
let current: { token: symbol; use: MicrophoneUse } | null = null;

/** Coordinate capture within the page, including pending permission and paused recording. */
export function claimChatMicrophone(use: MicrophoneUse): () => void {
    if (current) {
        throw new Error(
            current.use === 'voice_note'
                ? use !== 'voice_note'
                    ? 'Stop and preview or discard your voice message before starting or answering a call.'
                    : 'Finish or discard the other voice message before recording another.'
                : current.use === 'call_check'
                  ? 'Stop the microphone check before starting a call or recording a voice message.'
                  : 'Finish the voice call before recording a voice message. Your text draft is kept.',
        );
    }
    const token = Symbol(use);
    current = { token, use };
    return () => {
        if (current?.token === token) current = null;
    };
}
