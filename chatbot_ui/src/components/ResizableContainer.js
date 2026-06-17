import React from 'react';
import { ResizableBox } from 'react-resizable';
import 'react-resizable/css/styles.css';

const ResizableContainer = ({ children, width, height, minConstraints, maxConstraints, onResizeStop, onResize }) => {
    return (
        <ResizableBox
            width={width}
            height={height}
            minConstraints={minConstraints}
            maxConstraints={maxConstraints}
            resizeHandles={['se']}
            onResize={onResize}
            onResizeStop={onResizeStop}
            lockAspectRatio={false} // Lock aspect ratio disabled for flexibility
        >
            <div style={{ height: '100%', width: '100%', position: 'absolute' }}> {/* Ensure padding for footer */}
                {children}
            </div>
        </ResizableBox>
    );
};

export default ResizableContainer;
