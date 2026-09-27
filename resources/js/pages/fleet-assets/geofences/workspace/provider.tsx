import { createContext, useContext } from 'react';
export type MapProvider = { url: string; attribution: string };
export const MapProviderContext = createContext<MapProvider>({
    url: '',
    attribution: '',
});
export const useMapProvider = () => useContext(MapProviderContext);
