import { createContext, useContext } from 'react';
export const PrototypeContext = createContext(null);
export const usePrototype = () => useContext(PrototypeContext);
